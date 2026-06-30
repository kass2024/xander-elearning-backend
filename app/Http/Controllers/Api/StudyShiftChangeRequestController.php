<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\StudyShift;
use App\Models\StudyShiftChangeRequest;
use App\Models\User;
use App\Services\EnrollmentStudyShiftService;
use App\Support\ApiListCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudyShiftChangeRequestController extends Controller
{
    public function __construct(protected EnrollmentStudyShiftService $shifts)
    {
    }

    public function index(Request $request)
    {
        $actor = $this->resolveActor($request);
        if (!$actor) {
            return response()->json(['message' => 'Email is required.'], 401);
        }

        $status = $request->query('status', 'pending');
        $courseId = $request->query('course_id');

        $query = StudyShiftChangeRequest::query()
            ->with(['student', 'course', 'enrollment.studyShifts'])
            ->orderByDesc('created_at');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($courseId) {
            $query->where('course_id', (int) $courseId);
        }

        if ($this->isInstructor($actor) && !$this->isAdmin($actor)) {
            $courseIds = $actor->assignedCourses()->pluck('courses.id');
            $query->whereIn('course_id', $courseIds->isEmpty() ? [-1] : $courseIds);
        } elseif (!$this->isAdmin($actor)) {
            return response()->json(['message' => 'You are not allowed to view shift change requests.'], 403);
        }

        $rows = $query->get()->map(fn (StudyShiftChangeRequest $row) => $this->serializeRequest($row));

        return response()->json(['requests' => $rows], 200);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'course_id' => 'required|integer|exists:courses,id',
            'study_shift_ids' => 'required|array|min:1',
            'study_shift_ids.*' => 'integer|exists:study_shifts,id',
            'reason' => 'nullable|string|max:2000',
        ]);

        $enrollment = CourseEnrollment::query()
            ->with(['course', 'studyShifts'])
            ->where('student_id', $data['student_id'])
            ->where('course_id', $data['course_id'])
            ->first();

        if (!$enrollment) {
            return response()->json(['message' => 'Enrollment not found for this course.'], 404);
        }

        $existingPending = StudyShiftChangeRequest::query()
            ->where('course_enrollment_id', $enrollment->id)
            ->where('status', 'pending')
            ->exists();

        if ($existingPending) {
            return response()->json([
                'message' => 'You already have a pending shift change request for this course.',
            ], 422);
        }

        $course = $enrollment->course ?? Course::find($data['course_id']);
        $resolved = $this->shifts->resolveStudyShiftsForCourse(
            $course,
            $data['study_shift_ids'],
            $enrollment->id,
            true
        );

        if ($resolved instanceof \Illuminate\Http\JsonResponse) {
            return $resolved;
        }

        $currentIds = $this->shifts->formatShiftIds($enrollment->studyShifts);
        $requestedIds = collect($resolved)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        sort($currentIds);
        $sortedRequested = $requestedIds;
        sort($sortedRequested);

        if ($currentIds === $sortedRequested) {
            return response()->json([
                'message' => 'The selected shifts are the same as your current schedule.',
            ], 422);
        }

        $changeRequest = StudyShiftChangeRequest::create([
            'course_enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'course_id' => $enrollment->course_id,
            'current_study_shift_ids' => $currentIds,
            'requested_study_shift_ids' => $requestedIds,
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
        ]);

        $changeRequest->load(['student', 'course', 'enrollment.studyShifts']);

        return response()->json([
            'message' => 'Shift change request submitted. An instructor or admin will review it.',
            'request' => $this->serializeRequest($changeRequest),
        ], 201);
    }

    public function updateEnrollmentShifts(Request $request, Course $course)
    {
        $data = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'study_shift_ids' => 'present|array',
            'study_shift_ids.*' => 'integer|exists:study_shifts,id',
            'email' => 'nullable|email',
        ]);

        $actor = $this->resolveActor($request);
        if (!$actor) {
            return response()->json(['message' => 'Email is required.'], 401);
        }

        if (!$this->canManageCourse($actor, (int) $course->id)) {
            return response()->json(['message' => 'You are not allowed to update shifts for this course.'], 403);
        }

        $enrollment = CourseEnrollment::query()
            ->with('studyShifts')
            ->where('student_id', $data['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment) {
            return response()->json(['message' => 'Enrollment not found for this student and course.'], 404);
        }

        $result = $this->shifts->syncEnrollmentStudyShifts(
            $enrollment,
            $data['study_shift_ids'],
            StudyShift::query()->where('course_id', $course->id)->where('is_active', true)->exists()
                && count($data['study_shift_ids']) === 0
        );

        if ($result instanceof \Illuminate\Http\JsonResponse) {
            return $result;
        }

        StudyShiftChangeRequest::query()
            ->where('course_enrollment_id', $enrollment->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'approved',
                'reviewed_by' => $actor->id,
                'review_notes' => 'Shifts updated directly by staff.',
                'reviewed_at' => now(),
            ]);

        ApiListCache::bump('study_shifts');

        return response()->json([
            'message' => 'Study shifts updated successfully.',
            'study_shifts' => $result['study_shifts'],
        ], 200);
    }

    public function approve(Request $request, StudyShiftChangeRequest $studyShiftChangeRequest)
    {
        $actor = $this->resolveActor($request);
        if (!$actor) {
            return response()->json(['message' => 'Email is required.'], 401);
        }

        if (!$this->canManageCourse($actor, (int) $studyShiftChangeRequest->course_id)) {
            return response()->json(['message' => 'You are not allowed to approve this request.'], 403);
        }

        if ($studyShiftChangeRequest->status !== 'pending') {
            return response()->json(['message' => 'This request has already been processed.'], 422);
        }

        $enrollment = $studyShiftChangeRequest->enrollment()->with('course')->first();
        if (!$enrollment) {
            return response()->json(['message' => 'Enrollment not found.'], 404);
        }

        $result = $this->shifts->syncEnrollmentStudyShifts(
            $enrollment,
            $studyShiftChangeRequest->requested_study_shift_ids ?? [],
            true
        );

        if ($result instanceof \Illuminate\Http\JsonResponse) {
            return $result;
        }

        $studyShiftChangeRequest->update([
            'status' => 'approved',
            'reviewed_by' => $actor->id,
            'review_notes' => $request->input('review_notes'),
            'reviewed_at' => now(),
        ]);

        ApiListCache::bump('study_shifts');

        return response()->json([
            'message' => 'Shift change request approved.',
            'request' => $this->serializeRequest($studyShiftChangeRequest->fresh(['student', 'course', 'enrollment.studyShifts'])),
            'study_shifts' => $result['study_shifts'],
        ], 200);
    }

    public function reject(Request $request, StudyShiftChangeRequest $studyShiftChangeRequest)
    {
        $data = $request->validate([
            'review_notes' => 'nullable|string|max:2000',
        ]);

        $actor = $this->resolveActor($request);
        if (!$actor) {
            return response()->json(['message' => 'Email is required.'], 401);
        }

        if (!$this->canManageCourse($actor, (int) $studyShiftChangeRequest->course_id)) {
            return response()->json(['message' => 'You are not allowed to reject this request.'], 403);
        }

        if ($studyShiftChangeRequest->status !== 'pending') {
            return response()->json(['message' => 'This request has already been processed.'], 422);
        }

        $studyShiftChangeRequest->update([
            'status' => 'rejected',
            'reviewed_by' => $actor->id,
            'review_notes' => $data['review_notes'] ?? null,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Shift change request rejected.',
            'request' => $this->serializeRequest($studyShiftChangeRequest->fresh(['student', 'course', 'enrollment.studyShifts'])),
        ], 200);
    }

    private function serializeRequest(StudyShiftChangeRequest $row): array
    {
        $student = $row->student;
        $course = $row->course;
        $currentShifts = StudyShift::query()
            ->whereIn('id', $row->current_study_shift_ids ?? [])
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();
        $requestedShifts = StudyShift::query()
            ->whereIn('id', $row->requested_study_shift_ids ?? [])
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        return [
            'id' => $row->id,
            'course_enrollment_id' => $row->course_enrollment_id,
            'student_id' => $row->student_id,
            'student_name' => $student?->name ?? trim(($student?->first_name ?? '') . ' ' . ($student?->last_name ?? '')),
            'student_email' => $student?->email,
            'course_id' => $row->course_id,
            'course_title' => $course?->title,
            'status' => $row->status,
            'reason' => $row->reason,
            'review_notes' => $row->review_notes,
            'created_at' => $row->created_at?->toIso8601String(),
            'reviewed_at' => $row->reviewed_at?->toIso8601String(),
            'current_shifts' => $this->shifts->formatStudyShiftsForApi($currentShifts),
            'requested_shifts' => $this->shifts->formatStudyShiftsForApi($requestedShifts),
        ];
    }

    private function resolveActor(Request $request): ?User
    {
        if ($user = Auth::user()) {
            return $user;
        }

        $email = $request->query('email')
            ?? $request->input('email')
            ?? $request->header('X-User-Email');

        if (!$email) {
            return null;
        }

        return User::query()->where('email', $email)->first();
    }

    private function isAdmin(User $user): bool
    {
        return in_array(strtolower((string) $user->role), ['admin', 'superadmin', 'staff'], true);
    }

    private function isInstructor(User $user): bool
    {
        return strtolower((string) $user->role) === 'instructor';
    }

    private function canManageCourse(User $user, int $courseId): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if (!$this->isInstructor($user)) {
            return false;
        }

        return Course::query()
            ->where('id', $courseId)
            ->whereHas('instructors', fn ($q) => $q->where('users.id', $user->id))
            ->exists();
    }
}
