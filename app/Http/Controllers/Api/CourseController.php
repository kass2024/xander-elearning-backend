<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Course;
use App\Models\User;
use App\Models\CourseEnrollment;
use App\Models\Student;
use App\Models\CourseMaterial;
use App\Models\StudyShift;
use App\Support\ApiListCache;
use Illuminate\Support\Facades\Log;
use App\Mail\CourseAppliedMail;
use App\Mail\CourseEnrollmentApprovedMail;
use App\Mail\CourseEnrollmentRejectedMail;
use App\Mail\CoursePaymentLinkMail;
use App\Mail\CourseClassScheduledMail;
use App\Mail\StaffClassScheduledMail;
use App\Services\MailDeliveryService;
use App\Services\ZoomService;
use App\Support\CourseDetailsHelper;
use App\Support\EnrollmentStatusHelper;
use App\Support\PlatformTenantScope;
use App\Support\ResolvesEnrollmentStaff;
use App\Services\CourseCodeGenerator;
use Carbon\Carbon;

class CourseController extends Controller
{
    use ResolvesEnrollmentStaff;

    protected ZoomService $zoom;

    protected MailDeliveryService $mail;

    public function __construct(ZoomService $zoom, MailDeliveryService $mail)
    {
        $this->zoom = $zoom;
        $this->mail = $mail;
    }
    public function index(Request $request)
    {
        $programId = $request->query('program_id');
        $tenantId = PlatformTenantScope::resolveTenantId($request);
        $cacheKey = ($tenantId ? 'inst_' . $tenantId . '_' : '') . ($programId ? 'program_' . $programId : 'all');

        if ($tenantId !== null) {
            $query = Course::with('program:id,name')
                ->where('platform_institution_id', $tenantId)
                ->orderByDesc('id');

            if ($programId) {
                $query->where('program_id', $programId);
            }

            return response()->json($query->get(), 200);
        }

        $courses = ApiListCache::remember('courses', $cacheKey, 120, function () use ($programId) {
            $query = Course::with('program:id,name')
                ->orderByDesc('id');

            if ($programId) {
                $query->where('program_id', $programId);
            }

            return $query->get();
        });

        return response()->json($courses, 200);
    }

    public function suggestCode(Request $request)
    {
        $title = (string) $request->query('title', '');
        $prefix = $request->query('prefix');

        return response()->json([
            'course_code' => CourseCodeGenerator::generate($prefix, $title),
            'prefixes' => CourseCodeGenerator::PREFIXES,
        ], 200);
    }

    public function store(Request $request)
    {
        $data = $request->validate(array_merge([
            'program_id' => 'required|integer|exists:elearning_programs,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric',
            'duration' => 'nullable|string|max:255',
            'requirements' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            'instructor_id' => 'required|integer|exists:users,id',
            'image' => 'nullable',
        ], CourseDetailsHelper::validationRules()));

        $details = CourseDetailsHelper::extractFromRequest($request);

        $payload = [
            'program_id' => $data['program_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'] ?? null,
            'duration' => $data['duration'] ?? null,
            'requirements' => $data['requirements'] ?? null,
            'status' => $data['status'] ?? 'Active',
        ];

        CourseDetailsHelper::applyToPayload($payload, $details, $data['title']);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('uploads', 'public');
            $payload['image'] = asset('storage/' . $path);
        }

        PlatformTenantScope::stampInstitutionId($request, $payload);

        $instructor = User::findOrFail($data['instructor_id']);
        if ($instructor->role !== 'instructor') {
            return response()->json([
                'message' => 'Selected user is not an instructor.',
            ], 422);
        }

        $tenantId = PlatformTenantScope::resolvePartnerTenantId($request);
        if ($tenantId !== null && (int) $instructor->platform_institution_id !== (int) $tenantId) {
            return response()->json([
                'message' => 'Instructor must belong to your institution.',
            ], 422);
        }

        $course = Course::create($payload);
        $instructor->assignedCourses()->syncWithoutDetaching([$course->id]);

        $this->bumpCourseCaches();

        return response()->json([
            'message' => 'Course created',
            'course' => $course->load('instructors:id,name,email'),
        ], 201);
    }

    public function update(Request $request, Course $course)
    {
        PlatformTenantScope::assertCanAccess($request, $course);
        $data = $request->validate(array_merge([
            'program_id' => 'sometimes|required|integer|exists:elearning_programs,id',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric',
            'duration' => 'nullable|string|max:255',
            'requirements' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            'image' => 'nullable',
        ], CourseDetailsHelper::validationRules($course->id)));

        $details = CourseDetailsHelper::extractFromRequest($request);

        $updateData = $data;
        unset($updateData['image'], $updateData['auto_generate_code'], $updateData['code_prefix']);

        $detailsPayload = [];
        CourseDetailsHelper::applyToPayload($detailsPayload, $details, $course->title ?? $data['title'] ?? null);

        $course->fill(array_merge($updateData, $detailsPayload));

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('uploads', 'public');
            $course->image = asset('storage/' . $path);
        }

        $course->save();

        $this->bumpCourseCaches();

        return response()->json([
            'message' => 'Course updated',
            'course' => $course,
        ]);
    }

    public function destroy(Request $request, Course $course)
    {
        PlatformTenantScope::assertCanAccess($request, $course);
        $course->delete();
        $this->bumpCourseCaches();

        return response()->json(['message' => 'Course deleted']);
    }

    public function assignToUser(Request $request, Course $course)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($data['user_id']);

        $user->assignedCourses()->syncWithoutDetaching([$course->id]);

        $this->bumpCourseCaches();

        return response()->json([
            'message' => 'Course assigned to user',
        ]);
    }

    public function unassignFromUser(Request $request, Course $course)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($data['user_id']);

        $user->assignedCourses()->detach($course->id);

        $this->bumpCourseCaches();

        return response()->json([
            'message' => 'Course unassigned from user',
        ]);
    }

    protected function bumpCourseCaches(): void
    {
        ApiListCache::bump('courses');
        ApiListCache::bump('elearning_programs');
        ApiListCache::bump('instructors');
        ApiListCache::bump('analytics');
    }

    public function enroll(Request $request, Course $course)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'level' => 'nullable|string|max:255',
            'study_shift_id' => 'nullable|integer|exists:study_shifts,id',
            'study_shift_ids' => 'nullable|array',
            'study_shift_ids.*' => 'integer|exists:study_shifts,id',
            'auto_approve' => 'nullable|boolean',
        ]);

        $shiftIds = array_values(array_unique(array_filter(
            array_map('intval', $data['study_shift_ids'] ?? [])
        )));

        if ($shiftIds === [] && !empty($data['study_shift_id'])) {
            $shiftIds = [(int) $data['study_shift_id']];
        }

        $shiftsResult = $this->resolveEnrollmentStudyShifts($course, $shiftIds);
        if ($shiftsResult instanceof \Illuminate\Http\JsonResponse) {
            return $shiftsResult;
        }
        $studyShifts = $shiftsResult;

        // Check if the student is already enrolled in this course
        $existing = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'You have already applied for this course.',
                'enrollment' => $existing,
            ], 200);
        }

        $autoApprove = (bool) ($data['auto_approve'] ?? false);

        $enrollment = CourseEnrollment::create([
            'student_id' => $data['student_id'],
            'course_id' => $course->id,
            'status' => $autoApprove ? 'approved' : 'enrolled',
            'level' => $data['level'] ?? null,
            'study_shift_id' => $studyShifts !== [] ? $studyShifts[0]->id : null,
        ]);

        if ($studyShifts !== []) {
            $enrollment->studyShifts()->sync(collect($studyShifts)->pluck('id')->all());
        }

        $enrollment->load('studyShifts');

        $student = Student::find($data['student_id']);
        if ($student && $student->email) {
            if ($autoApprove) {
                $this->mail->sendTo(
                    $student->email,
                    new CourseEnrollmentApprovedMail($student, $course),
                    ['event' => 'enrollment_approved', 'student_id' => $data['student_id'], 'course_id' => $course->id]
                );
            } else {
                $this->mail->sendTo(
                    $student->email,
                    new CourseAppliedMail($student, $course, $enrollment->level),
                    ['event' => 'course_applied', 'student_id' => $data['student_id'], 'course_id' => $course->id]
                );
            }
        }

        return response()->json([
            'message' => $autoApprove
                ? 'Student enrolled with full course access. Payment can be collected later.'
                : 'Enrolled successfully',
            'enrollment' => array_merge($enrollment->toArray(), [
                'study_shifts' => $this->formatStudyShiftsForApi($enrollment->studyShifts),
            ]),
        ], 201);
    }

    /**
     * Admin or instructor approves a learner's course application — learner gets full access immediately.
     */
    public function approveEnrollment(Request $request, Course $course)
    {
        if ($denied = $this->assertCanManageCourseEnrollment($request, $course)) {
            return $denied;
        }

        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'email' => 'nullable|email',
            'instructor_email' => 'nullable|email',
        ]);

        $enrollment = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment) {
            return response()->json([
                'message' => 'Enrollment not found for this student and course.',
            ], 404);
        }

        if ($enrollment->status === 'rejected') {
            return response()->json([
                'message' => 'This enrollment was rejected and cannot be approved.',
            ], 422);
        }

        if (in_array($enrollment->status, ['paid', 'completed'], true)) {
            return response()->json([
                'message' => 'This enrollment is already active.',
            ], 422);
        }

        if ($enrollment->status === 'approved') {
            return response()->json([
                'message' => 'Enrollment is already approved. The learner has course access.',
                'enrollment' => $this->formatEnrollmentForApi($enrollment->fresh('studyShifts'), $course),
            ], 200);
        }

        if (!EnrollmentStatusHelper::isPendingApproval($enrollment->status)) {
            return response()->json([
                'message' => 'Only pending applications can be approved.',
            ], 422);
        }

        $enrollment->status = 'approved';
        $enrollment->save();

        $student = Student::find($data['student_id']);
        if ($student && $student->email) {
            $this->mail->sendTo(
                $student->email,
                new CourseEnrollmentApprovedMail($student, $course),
                ['event' => 'enrollment_approved', 'student_id' => $data['student_id'], 'course_id' => $course->id]
            );
        }

        return response()->json([
            'message' => 'Enrollment approved. The learner now has full access to course materials.',
            'enrollment' => $this->formatEnrollmentForApi($enrollment->fresh('studyShifts'), $course),
        ]);
    }

    public function scheduleClass(Request $request, Course $course)
    {
        $data = $request->validate([
            'start_time' => 'required|date',
            'instructor_email' => 'nullable|email',
            'topic' => 'nullable|string|max:255',
            'duration' => 'nullable|integer|min:15|max:480',
            'timezone' => 'nullable|string|max:64',
            'zoom_link' => 'nullable|url',
            'notes' => 'nullable|string',
            'staff_id' => 'nullable|exists:users,id',
            'notify_only' => 'nullable|boolean',
            'join_before_host' => 'nullable|boolean',
            'mute_upon_entry' => 'nullable|boolean',
            'auto_recording' => 'nullable|boolean',
        ]);

        $instructor = null;
        if (!empty($data['instructor_email'])) {
            $instructor = User::query()
                ->where('email', $data['instructor_email'])
                ->where('role', 'instructor')
                ->first();

            if (!$instructor) {
                return response()->json(['message' => 'Instructor not found.'], 404);
            }

            if (!$instructor->assignedCourses()->where('courses.id', $course->id)->exists()) {
                return response()->json([
                    'message' => 'You are not assigned to this course. Ask an administrator to assign it in Course Management.',
                ], 403);
            }
        }

        $zoomJoinLink = $data['zoom_link'] ?? null;
        $zoomStartUrl = null;
        $zoomMeetingId = null;
        $zoomPassword = null;
        $joinPwd = null;

        if (!$zoomJoinLink) {
            $hostId = (string) config('services.zoom.host_user_id', 'me');
            $topic = trim((string) ($data['topic'] ?? '')) ?: ($course->title ?? 'Course Class');

            $zoomPayload = [
                'topic' => $topic,
                'start_time' => $data['start_time'],
                'duration' => $data['duration'] ?? 60,
                'timezone' => $data['timezone'] ?? config('app.timezone', 'UTC'),
                'agenda' => $data['notes'] ?? '',
                'join_before_host' => (bool) ($data['join_before_host'] ?? false),
                'waiting_room' => !((bool) ($data['join_before_host'] ?? false)),
                'mute_upon_entry' => (bool) ($data['mute_upon_entry'] ?? true),
                'auto_recording' => (bool) ($data['auto_recording'] ?? false),
            ];

            $zoomData = $this->zoom->createMeeting($zoomPayload, $hostId);

            if ($zoomData === null) {
                return response()->json([
                    'message' => 'Zoom API is not configured. Add ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID, and ZOOM_CLIENT_SECRET to .env.',
                ], 503);
            }

            if (isset($zoomData['error']) && !empty($zoomData['error'])) {
                $body = $zoomData['body'] ?? [];
                $message = $body['message'] ?? 'Zoom returned an error while creating the meeting.';

                return response()->json([
                    'message' => $message,
                    'zoom' => $body,
                ], 422);
            }

            $zoomJoinLink = $zoomData['join_url'] ?? null;
            $zoomStartUrl = $zoomData['start_url'] ?? null;
            $zoomMeetingId = $zoomData['id'] ?? null;
            $zoomPassword = $zoomData['password'] ?? null;
            $joinPwd = $this->zoom->extractPasswordFromJoinUrl($zoomJoinLink);

            if (!$zoomJoinLink) {
                return response()->json([
                    'message' => 'Zoom meeting created but join link was not returned.',
                    'zoom' => $zoomData,
                ], 500);
            }
        }

        $staff = null;
        if (!empty($data['staff_id'])) {
            $staff = User::find($data['staff_id']);
        } elseif ($instructor) {
            $staff = $instructor;
        } elseif ($request->user()) {
            $staff = $request->user();
        }

        $notifyOnly = !empty($data['notify_only']);
        $material = null;
        if (!$notifyOnly) {
            $materialTitle = trim((string) ($data['topic'] ?? '')) ?: ('Live class - ' . Carbon::parse($data['start_time'])->format('M j, Y g:i A'));
            if ($joinPwd === null && is_string($zoomJoinLink)) {
                $joinPwd = $this->zoom->extractPasswordFromJoinUrl($zoomJoinLink);
            }
            $material = CourseMaterial::create([
                'course_id' => $course->id,
                'title' => $materialTitle,
                'description' => $data['notes'] ?? null,
                'type' => 'zoom',
                'resource_url' => $zoomJoinLink,
                'scheduled_at' => $data['start_time'],
                'metadata' => [
                    'join_url' => $zoomJoinLink,
                    'start_url' => $zoomStartUrl,
                    'meeting_id' => $zoomMeetingId,
                    'password' => $zoomPassword,
                    'join_pwd' => $joinPwd,
                    'duration' => $data['duration'] ?? 60,
                    'timezone' => $data['timezone'] ?? config('app.timezone', 'UTC'),
                ],
                'sort_order' => 0,
            ]);
        }

        $hostRoomUrl = $material ? CourseMaterialHelper::embedRoomUrl($material, 1) : null;
        $hostRoomPath = $material ? CourseMaterialHelper::embedRoomPath($material, 1) : null;
        $learnerPortalUrl = CourseMaterialHelper::learnerPortalUrl();

        if ($staff && !empty($staff->email)) {
            $this->mail->sendTo(
                $staff->email,
                new StaffClassScheduledMail(
                    $staff,
                    $course,
                    $data['start_time'],
                    $hostRoomUrl ?? $learnerPortalUrl,
                    $data['notes'] ?? null,
                    $hostRoomUrl,
                    CourseMaterialHelper::instructorClassesUrl(),
                ),
                ['event' => 'staff_class_scheduled', 'course_id' => $course->id, 'staff_id' => $staff->id]
            );
        }

        $notifiedCount = 0;
        $learners = CourseEnrollment::query()
            ->with('student')
            ->where('course_id', $course->id)
            ->whereIn('status', ['paid', 'completed'])
            ->get();

        foreach ($learners as $enrollment) {
            $student = $enrollment->student;
            if (!$student || empty($student->email)) {
                continue;
            }

            $studentJoinUrl = $material
                ? CourseMaterialHelper::embedRoomUrl($material, 0, (int) $student->id)
                : $learnerPortalUrl;

            if ($this->mail->sendTo(
                $student->email,
                new CourseClassScheduledMail(
                    $student,
                    $course,
                    $data['start_time'],
                    $studentJoinUrl ?? $learnerPortalUrl,
                    $data['notes'] ?? null,
                    $learnerPortalUrl,
                ),
                ['event' => 'learner_class_scheduled', 'course_id' => $course->id, 'student_id' => $student->id]
            )) {
                $notifiedCount++;
            }
        }

        return response()->json([
            'message' => 'Class scheduled via Zoom API. Learners have been notified where possible.',
            'host_room_url' => $hostRoomUrl,
            'host_room_path' => $hostRoomPath,
            'learner_portal_url' => $learnerPortalUrl,
            'zoom_meeting_id' => $zoomMeetingId,
            'students_notified' => $notifiedCount,
            'material' => $material ? CourseMaterialHelper::toLearnerArray($material) : null,
        ]);
    }

    public function enrolledStudents(Course $course)
    {
        $enrollments = CourseEnrollment::with(['student', 'studyShifts'])
            ->where('course_id', $course->id)
            ->get();

        $students = $enrollments->map(function ($enrollment) {
            $student = $enrollment->student;
            if (!$student) {
                return null;
            }

            return [
                'id' => $student->id,
                'first_name' => $student->first_name ?? null,
                'last_name' => $student->last_name ?? null,
                'name' => $student->name ?? trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? '')),
                'email' => $student->email,
                'enrollment_status' => $enrollment->status,
                'study_shifts' => $this->formatStudyShiftsForApi($enrollment->studyShifts),
            ];
        })->filter()->values();

        $notifyableCount = $students->filter(
            fn ($s) => in_array(strtolower((string) ($s['enrollment_status'] ?? '')), ['paid', 'completed'], true)
        )->count();

        return response()->json([
            'students' => $students,
            'notifyable_count' => $notifyableCount,
        ]);
    }

    public function markPaid(Request $request, Course $course)
    {
        if ($denied = $this->assertCanManageCourseEnrollment($request, $course)) {
            return $denied;
        }

        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'email' => 'nullable|email',
            'instructor_email' => 'nullable|email',
        ]);

        $enrollment = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment) {
            return response()->json([
                'message' => 'Enrollment not found for this student and course.',
            ], 404);
        }

        if (!in_array($enrollment->status, ['approved', 'paid'], true)) {
            return response()->json([
                'message' => 'Enrollment must be approved before payment can be recorded.',
            ], 422);
        }

        $enrollment->status = 'paid';
        $enrollment->save();

        return response()->json([
            'message' => 'Enrollment marked as paid.',
            'enrollment' => $this->formatEnrollmentForApi($enrollment->fresh('studyShifts'), $course),
        ]);
    }

    public function rejectEnrollment(Request $request, Course $course)
    {
        if ($denied = $this->assertCanManageCourseEnrollment($request, $course)) {
            return $denied;
        }

        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'reason' => 'nullable|string|max:2000',
            'email' => 'nullable|email',
            'instructor_email' => 'nullable|email',
        ]);

        $enrollment = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment) {
            return response()->json([
                'message' => 'Enrollment not found for this student and course.',
            ], 404);
        }

        $enrollment->status = 'rejected';
        $enrollment->save();

        $student = Student::find($data['student_id']);
        if ($student && $student->email) {
            $this->mail->sendTo(
                $student->email,
                new CourseEnrollmentRejectedMail($student, $course, $data['reason'] ?? null),
                ['event' => 'enrollment_rejected', 'student_id' => $data['student_id'], 'course_id' => $course->id]
            );
        }

        return response()->json([
            'message' => 'Enrollment rejected.',
            'enrollment' => $this->formatEnrollmentForApi($enrollment->fresh('studyShifts'), $course),
        ]);
    }

    /**
     * Remove a learner from a course (e.g. refused to pay). Not allowed for paid/completed enrollments.
     */
    public function removeEnrollment(Request $request, Course $course)
    {
        if ($denied = $this->assertCanManageCourseEnrollment($request, $course)) {
            return $denied;
        }

        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'reason' => 'nullable|string|max:2000',
            'email' => 'nullable|email',
            'instructor_email' => 'nullable|email',
        ]);

        $enrollment = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment) {
            return response()->json([
                'message' => 'Enrollment not found for this student and course.',
            ], 404);
        }

        if (EnrollmentStatusHelper::isPaid($enrollment->status)) {
            return response()->json([
                'message' => 'Paid enrollments cannot be removed. Contact an administrator if a refund is required.',
            ], 422);
        }

        $enrollment->studyShiftLinks()->delete();
        $enrollment->studyShifts()->detach();
        $enrollment->delete();

        return response()->json([
            'message' => 'Learner removed from this course.',
        ]);
    }

    /**
     * Create a Stripe checkout link and optionally email it to the learner.
     */
    public function sendPaymentLink(Request $request, Course $course)
    {
        if ($denied = $this->assertCanManageCourseEnrollment($request, $course)) {
            return $denied;
        }

        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'send_email' => 'nullable|boolean',
            'email' => 'nullable|email',
            'instructor_email' => 'nullable|email',
        ]);

        $enrollment = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment) {
            return response()->json([
                'message' => 'Enrollment not found for this student and course.',
            ], 404);
        }

        if (!EnrollmentStatusHelper::canPay($enrollment->status)) {
            return response()->json([
                'message' => 'Payment links can only be sent for approved enrollments that are not yet paid.',
            ], 422);
        }

        $stripe = app(\App\Services\StripePaymentService::class);
        $result = $stripe->createCheckoutSession($course, (int) $data['student_id']);

        if (empty($result['ok'])) {
            return response()->json([
                'message' => $result['message'] ?? 'Unable to create payment link.',
            ], $result['status'] ?? 500);
        }

        $paymentUrl = $result['url'];
        $student = Student::find($data['student_id']);
        $sendEmail = $data['send_email'] ?? true;

        if ($sendEmail && $student?->email) {
            $this->mail->sendTo(
                $student->email,
                new CoursePaymentLinkMail(
                    $student,
                    $course,
                    $paymentUrl,
                    (float) ($course->price ?? 0)
                ),
                ['event' => 'payment_link_sent', 'student_id' => $data['student_id'], 'course_id' => $course->id]
            );
        }

        return response()->json([
            'message' => $sendEmail && $student?->email
                ? 'Payment link sent to the learner by email.'
                : 'Payment link created.',
            'payment_url' => $paymentUrl,
        ]);
    }

    public function studentEnrollments(Student $student)
    {
        $enrollments = CourseEnrollment::with(['studyShifts', 'course:id,title,price'])
            ->where('student_id', $student->id)
            ->get();

        return response()->json([
            'enrollments' => $enrollments->map(
                fn (CourseEnrollment $enrollment) => $this->formatEnrollmentForApi($enrollment, $enrollment->course)
            ),
        ]);
    }

    private function formatEnrollmentForApi(CourseEnrollment $enrollment, ?Course $course = null): array
    {
        $course ??= $enrollment->course;
        $status = (string) ($enrollment->status ?? 'enrolled');

        return [
            'enrollment_id' => (int) $enrollment->id,
            'course_id' => (int) $enrollment->course_id,
            'course_title' => $course?->title,
            'course_price' => (float) ($course?->price ?? 0),
            'status' => $status,
            'payment_paid' => EnrollmentStatusHelper::isPaid($status),
            'has_access' => EnrollmentStatusHelper::hasCourseAccess($status),
            'level' => $enrollment->level,
            'study_shifts' => $this->formatStudyShiftsForApi($enrollment->studyShifts),
        ];
    }

    private const STUDY_SHIFT_DAY_NAMES = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    /**
     * @return array<int, StudyShift>|\Illuminate\Http\JsonResponse
     */
    private function resolveEnrollmentStudyShifts(Course $course, array $shiftIds)
    {
        if ($shiftIds === []) {
            $hasShifts = StudyShift::query()
                ->where('is_active', true)
                ->where(function ($q) use ($course) {
                    $q->where('course_id', $course->id)->orWhereNull('course_id');
                })
                ->exists();

            if ($hasShifts) {
                return response()->json([
                    'message' => 'Please select at least one study shift for this course.',
                ], 422);
            }

            return [];
        }

        $shifts = StudyShift::query()
            ->whereIn('id', $shiftIds)
            ->where('is_active', true)
            ->where(function ($q) use ($course) {
                $q->where('course_id', $course->id)->orWhereNull('course_id');
            })
            ->withCount('enrollmentLinks')
            ->get();

        if ($shifts->count() !== count($shiftIds)) {
            return response()->json([
                'message' => 'One or more selected study shifts are not available for this course.',
            ], 422);
        }

        $days = $shifts->pluck('day_of_week')->map(fn ($day) => (int) $day);
        if ($days->count() !== $days->unique()->count()) {
            return response()->json([
                'message' => 'You can only select one shift per day.',
            ], 422);
        }

        foreach ($shifts as $shift) {
            if ($shift->max_students && $shift->enrollment_links_count >= $shift->max_students) {
                $dayLabel = self::STUDY_SHIFT_DAY_NAMES[(int) $shift->day_of_week] ?? 'Day';

                return response()->json([
                    'message' => sprintf(
                        'The %s shift on %s is full. Please choose another time.',
                        $shift->name,
                        $dayLabel
                    ),
                ], 422);
            }
        }

        return $shifts->sortBy([
            ['day_of_week', 'asc'],
            ['start_time', 'asc'],
        ])->values()->all();
    }

    private function formatStudyShiftsForApi($shifts): array
    {
        return collect($shifts)->map(function (StudyShift $shift) {
            $dayLabel = self::STUDY_SHIFT_DAY_NAMES[(int) $shift->day_of_week] ?? 'Day';
            $start = substr((string) $shift->start_time, 0, 5);
            $end = substr((string) $shift->end_time, 0, 5);

            return [
                'id' => $shift->id,
                'name' => $shift->name,
                'day_of_week' => (int) $shift->day_of_week,
                'day_label' => $dayLabel,
                'start_time' => $start,
                'end_time' => $end,
                'label' => sprintf('%s · %s %s–%s', $shift->name, $dayLabel, $start, $end),
            ];
        })->values()->all();
    }
}
