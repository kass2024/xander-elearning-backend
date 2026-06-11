<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Course;
use App\Models\User;
use App\Models\CourseEnrollment;
use App\Models\Student;
use App\Models\CourseMaterial;
use Illuminate\Support\Facades\Log;
use App\Mail\CourseAppliedMail;
use App\Mail\CourseEnrollmentApprovedMail;
use App\Mail\CourseEnrollmentRejectedMail;
use App\Mail\CourseClassScheduledMail;
use App\Mail\StaffClassScheduledMail;
use App\Services\MailDeliveryService;
use App\Services\ZoomService;
use App\Support\CourseMaterialHelper;
use Carbon\Carbon;

class CourseController extends Controller
{
    protected ZoomService $zoom;

    protected MailDeliveryService $mail;

    public function __construct(ZoomService $zoom, MailDeliveryService $mail)
    {
        $this->zoom = $zoom;
        $this->mail = $mail;
    }
    public function index()
    {
        return response()->json(Course::orderByDesc('id')->get(), 200);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric',
            'duration' => 'nullable|string|max:255',
            'requirements' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            // accept any nullable value; we'll only treat it as an upload if it's a real file
            'image' => 'nullable',
        ]);

        $payload = [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'] ?? null,
            'duration' => $data['duration'] ?? null,
            'requirements' => $data['requirements'] ?? null,
            'status' => $data['status'] ?? 'Active',
        ];

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('uploads', 'public');
            $payload['image'] = asset('storage/' . $path);
        }

        $course = Course::create($payload);

        return response()->json([
            'message' => 'Course created',
            'course' => $course,
        ], 201);
    }

    public function update(Request $request, Course $course)
    {
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric',
            'duration' => 'nullable|string|max:255',
            'requirements' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            // accept any nullable value; only handle as file when present as upload
            'image' => 'nullable',
        ]);

        $updateData = $data;
        // Remove image from mass-assign data; handle file separately
        unset($updateData['image']);

        $course->fill($updateData);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('uploads', 'public');
            $course->image = asset('storage/' . $path);
        }

        $course->save();

        return response()->json([
            'message' => 'Course updated',
            'course' => $course,
        ]);
    }

    public function destroy(Course $course)
    {
        $course->delete();
        return response()->json(['message' => 'Course deleted']);
    }

    public function assignToUser(Request $request, Course $course)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($data['user_id']);

        $user->assignedCourses()->syncWithoutDetaching([$course->id]);

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

        return response()->json([
            'message' => 'Course unassigned from user',
        ]);
    }

    public function enroll(Request $request, Course $course)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'level' => 'nullable|string|max:255',
        ]);

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

        $enrollment = CourseEnrollment::create([
            'student_id' => $data['student_id'],
            'course_id' => $course->id,
            'status' => 'enrolled',
            'level' => $data['level'] ?? null,
        ]);

        // Send notification email to the student about the course application
        $student = Student::find($data['student_id']);
        if ($student && $student->email) {
            $this->mail->sendTo(
                $student->email,
                new CourseAppliedMail($student, $course, $enrollment->level),
                ['event' => 'course_applied', 'student_id' => $data['student_id'], 'course_id' => $course->id]
            );
        }

        return response()->json([
            'message' => 'Enrolled successfully',
            'enrollment' => $enrollment,
        ], 201);
    }

    /**
     * Admin approves a learner's course application so they can pay via Stripe.
     */
    public function approveEnrollment(Request $request, Course $course)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
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
            'message' => 'Enrollment approved. The learner can now complete payment.',
            'enrollment' => $enrollment,
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

        if ($staff && !empty($staff->email)) {
            $this->mail->sendTo(
                $staff->email,
                new StaffClassScheduledMail(
                    $staff,
                    $course,
                    $data['start_time'],
                    $zoomJoinLink,
                    $data['notes'] ?? null
                ),
                ['event' => 'staff_class_scheduled', 'course_id' => $course->id, 'staff_id' => $staff->id]
            );
        }

        $notifyOnly = !empty($data['notify_only']);
        $material = null;
        if (!$notifyOnly) {
            $materialTitle = trim((string) ($data['topic'] ?? '')) ?: ('Live class - ' . Carbon::parse($data['start_time'])->format('M j, Y g:i A'));
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
                    'duration' => $data['duration'] ?? 60,
                    'timezone' => $data['timezone'] ?? config('app.timezone', 'UTC'),
                ],
                'sort_order' => 0,
            ]);
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

            if ($this->mail->sendTo(
                $student->email,
                new CourseClassScheduledMail(
                    $student,
                    $course,
                    $data['start_time'],
                    $zoomJoinLink,
                    $data['notes'] ?? null
                ),
                ['event' => 'learner_class_scheduled', 'course_id' => $course->id, 'student_id' => $student->id]
            )) {
                $notifiedCount++;
            }
        }

        return response()->json([
            'message' => 'Class scheduled via Zoom API. Learners have been notified where possible.',
            'zoom_join_url' => $zoomJoinLink,
            'zoom_start_url' => $zoomStartUrl,
            'zoom_meeting_id' => $zoomMeetingId,
            'students_notified' => $notifiedCount,
            'material' => $material ? CourseMaterialHelper::toLearnerArray($material) : null,
        ]);
    }

    public function enrolledStudents(Course $course)
    {
        $enrollments = CourseEnrollment::with('student')
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
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
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
                'message' => 'Enrollment must be approved by an administrator before payment can be recorded.',
            ], 422);
        }

        $enrollment->status = 'paid';
        $enrollment->save();

        // Notify learner that their enrollment has been approved/activated
        $student = Student::find($data['student_id']);
        if ($student && $student->email) {
            $this->mail->sendTo(
                $student->email,
                new CourseEnrollmentApprovedMail($student, $course),
                ['event' => 'enrollment_approved', 'student_id' => $data['student_id'], 'course_id' => $course->id]
            );
        }

        return response()->json([
            'message' => 'Enrollment marked as paid.',
            'enrollment' => $enrollment,
        ]);
    }

    public function rejectEnrollment(Request $request, Course $course)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'reason' => 'nullable|string|max:2000',
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
            'enrollment' => $enrollment,
        ]);
    }

    public function studentEnrollments(Student $student)
    {
        $enrollments = CourseEnrollment::where('student_id', $student->id)
            ->get(['course_id', 'status', 'level']);

        return response()->json([
            'enrollments' => $enrollments->map(function ($enrollment) {
                return [
                    'course_id' => $enrollment->course_id,
                    'status' => $enrollment->status,
                    'level' => $enrollment->level,
                ];
            }),
        ]);
    }
}
