<?php



namespace App\Http\Controllers\Api;



use App\Http\Controllers\Controller;

use App\Models\Course;

use App\Models\CourseEnrollment;

use App\Models\CourseMaterial;

use App\Models\CoursePayment;

use App\Models\LiveZoomCohort;

use App\Models\Student;

use App\Http\Controllers\Api\CertificateController;

use App\Services\ZoomService;

use App\Support\CourseMaterialHelper;
use App\Support\EnrollmentStatusHelper;
use App\Support\QuizMaterialHelper;
use App\Support\LearnerRecordingAccess;

use Carbon\Carbon;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Schema;



class LearnerDashboardController extends Controller

{

    public function __construct(protected ZoomService $zoom)

    {

    }



    public function dashboard(Request $request)

    {

        $studentId = $request->query('student_id');

        if (!$studentId) {

            return response()->json(['message' => 'student_id is required'], 400);

        }



        $student = Student::find($studentId);

        if (!$student) {

            return response()->json(['message' => 'Student not found'], 404);

        }



        $enrollments = CourseEnrollment::query()

            ->with(['course.materials', 'studyShifts'])

            ->where('student_id', $student->id)

            ->orderByDesc('updated_at')

            ->get();

        $shiftService = app(\App\Services\EnrollmentStudyShiftService::class);

        $pendingShiftRequests = \App\Models\StudyShiftChangeRequest::query()

            ->where('student_id', $student->id)

            ->where('status', 'pending')

            ->get()

            ->keyBy('course_id');



        $enrolledCourseIds = $enrollments->pluck('course_id')->filter()->unique()->values();



        $paidOrCompleted = $enrollments->filter(
            fn (CourseEnrollment $e) => EnrollmentStatusHelper::isPaid($e->status)
        );

        $accessibleEnrollments = $enrollments->filter(
            fn (CourseEnrollment $e) => EnrollmentStatusHelper::hasCourseAccess($e->status)
        );

        $paidCourseIds = $paidOrCompleted->pluck('course_id')->filter()->unique()->values();
        $accessibleCourseIds = $accessibleEnrollments->pluck('course_id')->filter()->unique()->values();



        $certificates = $paidOrCompleted->map(function (CourseEnrollment $enrollment) use ($student) {

            $course = $enrollment->course;

            if (!$course) {

                return null;

            }



            $studentName = trim($student->name ?? '') ?: trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? ''));



            return [

                'course_id' => $course->id,

                'student_id' => $student->id,

                'course_title' => $course->title,

                'student_name' => $studentName ?: 'Learner',

                'status' => $enrollment->status,

                'certificate_id' => CertificateController::certificateId($student->id, $course->id),

                'issued_at' => $enrollment->updated_at?->toIso8601String(),

                'verify_url' => CertificateController::verifyUrl($course->id, $student->id),

            ];

        })->filter()->values();



        $enrolledCourses = $enrollments->map(function (CourseEnrollment $enrollment) use ($shiftService, $pendingShiftRequests) {

            $course = $enrollment->course;

            if (!$course) {

                return null;

            }



            $materials = $course->materials ?? collect();

            $videos = $materials->filter(fn ($m) => CourseMaterialHelper::materialKind($m) === 'video')->count();

            $documents = $materials->filter(fn ($m) => CourseMaterialHelper::materialKind($m) === 'document')->count();

            $quizzes = $materials->filter(fn ($m) => in_array(CourseMaterialHelper::materialKind($m), ['quiz', 'assessment'], true))->count();



            $status = strtolower((string) ($enrollment->status ?? 'enrolled'));

            $progress = match ($status) {
                'completed' => 100,
                'paid' => 75,
                'approved' => 40,
                'enrolled' => 15,
                default => 5,
            };



            return [

                'id' => $course->id,

                'enrollment_id' => $enrollment->id,

                'title' => $course->title,

                'description' => $course->description,

                'status' => $enrollment->status,
                'payment_paid' => EnrollmentStatusHelper::isPaid($status),
                'has_access' => EnrollmentStatusHelper::hasCourseAccess($status),

                'level' => $enrollment->level,

                'price' => (float) ($course->price ?? 0),

                'progress_percent' => $progress,

                'materials_count' => $materials->count(),

                'videos_count' => $videos,

                'documents_count' => $documents,

                'quizzes_count' => $quizzes,

                'enrolled_at' => $enrollment->created_at?->toIso8601String(),

                'study_shifts' => $shiftService->formatStudyShiftsForApi($enrollment->studyShifts),

                'shift_change_request' => $this->serializeLearnerShiftRequest(
                    $pendingShiftRequests->get($course->id),
                    $shiftService
                ),

                'course_code' => $course->course_code,
                'general_information' => $course->general_information,
                'important_information' => $course->important_information,
                'guidelines' => $course->guidelines ?? [],
                'how_to_use' => $course->how_to_use ?? [],
                'attendance_policy' => $course->attendance_policy,
                'assessment_policy' => $course->assessment_policy,
                'requirements' => $course->requirements,
                'duration' => $course->duration,

            ];

        })->filter()->values();



        $allMaterials = $accessibleCourseIds->isEmpty()
            ? collect()
            : CourseMaterial::query()->whereIn('course_id', $accessibleCourseIds)->get();



        $learningFeatures = [

            'hd_video_lessons' => $allMaterials->filter(fn ($m) => CourseMaterialHelper::materialKind($m) === 'video')->count(),

            'downloadable_resources' => $allMaterials->filter(fn ($m) => CourseMaterialHelper::materialKind($m) === 'document')->count(),

            'quizzes_assessments' => $allMaterials->filter(fn ($m) => in_array(CourseMaterialHelper::materialKind($m), ['quiz', 'assessment'], true))->count(),

            'mock_exams' => $allMaterials->filter(fn ($m) => str_contains(strtolower((string) $m->title), 'mock'))->count(),

            'live_classes' => $allMaterials->filter(fn ($m) => CourseMaterialHelper::materialKind($m) === 'zoom')->count(),

        ];



        $upcomingClasses = $this->buildUpcomingClasses($accessibleCourseIds);

        $notifications = $this->buildNotifications($accessibleCourseIds);



        $quizzes = $allMaterials

            ->filter(fn ($m) => in_array(CourseMaterialHelper::materialKind($m), ['quiz', 'assessment'], true))

            ->take(8)

            ->map(fn (CourseMaterial $m) => [

                'id' => $m->id,

                'title' => $m->title,

                'course_id' => $m->course_id,

                'resource_url' => $m->resource_url,

            ])

            ->values();



        $recentPayments = CoursePayment::query()

            ->with('course:id,title')

            ->where('student_id', $student->id)

            ->orderByDesc('id')

            ->limit(5)

            ->get()

            ->map(fn (CoursePayment $p) => [

                'id' => $p->id,

                'course_title' => $p->course?->title,

                'amount' => round($p->amount_cents / 100, 2),

                'currency' => strtoupper($p->currency ?? 'usd'),

                'status' => $p->status,

                'provider' => $p->provider ?? 'stripe',

                'paid_at' => $p->paid_at?->toIso8601String(),

            ]);



        $availableCourses = Course::query()

            ->where(function ($q) {

                $q->whereRaw('LOWER(COALESCE(status, "")) = ?', ['active'])

                    ->orWhereRaw('LOWER(COALESCE(status, "")) = ?', [''])

                    ->orWhereNull('status');

            })

            ->orderByDesc('id')

            ->get()

            ->map(fn (Course $c) => [

                'id' => $c->id,

                'title' => $c->title,

                'description' => $c->description,

                'price' => (float) ($c->price ?? 0),

                'duration' => $c->duration,

                'status' => $c->status,

            ]);



        $enrollmentStatuses = $enrollments->keyBy('course_id')->map(
            fn ($e) => strtolower(trim((string) ($e->status ?? 'enrolled')))
        );



        $hoursLearned = round($paidOrCompleted->count() * 12.5, 1);

        $streakDays = min(30, $paidOrCompleted->count() * 3);



        $stripeSecret = config('services.stripe.secret');

        $stripePublic = config('services.stripe.key');



        return response()->json([

            'student' => [

                'id' => $student->id,

                'name' => $student->name ?? trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? '')),

                'email' => $student->email,

            ],

            'stats' => [

                'courses_enrolled' => $enrollments->count(),

                'active_courses' => $accessibleEnrollments->count(),

                'hours_learned' => $hoursLearned,

                'certificates' => $certificates->count(),

                'streak_days' => $streakDays,

            ],

            'learning_features' => $learningFeatures,

            'enrolled_courses' => $enrolledCourses,

            'available_courses' => $availableCourses,

            'enrollment_statuses' => $enrollmentStatuses,

            'upcoming_classes' => $upcomingClasses,

            'notifications' => $notifications,

            'quizzes' => $quizzes,

            'certificates' => $certificates,

            'recent_payments' => $recentPayments,

            'stripe' => [

                'configured' => !empty($stripeSecret) && !empty($stripePublic),

                'publishable_key' => $stripePublic ?: null,

                'provider' => 'Stripe',

            ],

        ], 200);

    }



    public function notifications(Request $request)

    {

        $studentId = $request->query('student_id');

        if (!$studentId) {

            return response()->json(['message' => 'student_id is required'], 400);

        }



        $student = Student::find($studentId);

        if (!$student) {

            return response()->json(['message' => 'Student not found'], 404);

        }



        $paidCourseIds = CourseEnrollment::query()
            ->where('student_id', $student->id)
            ->whereIn('status', EnrollmentStatusHelper::accessStatuses())
            ->pluck('course_id')

            ->filter()

            ->unique()

            ->values();



        return response()->json([

            'notifications' => $this->buildNotifications($paidCourseIds),

        ], 200);

    }



    public function recordings(Request $request)
    {
        $studentId = $request->query('student_id');
        if (!$studentId) {
            return response()->json(['message' => 'student_id is required'], 400);
        }

        $student = Student::find($studentId);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $allowedMeetingIds = LearnerRecordingAccess::liveClassMeetingIdsForStudent((int) $studentId);
        if (empty($allowedMeetingIds)) {
            return response()->json([
                'recordings' => [],
                'message' => 'Recordings are available for your enrolled live classes.',
            ], 200);
        }

        $grouped = LearnerRecordingAccess::filterGroupedRecordings(
            $this->zoom->recordingsGroupedByMeetingId(),
            $allowedMeetingIds
        );

        return response()->json([
            'recordings' => LearnerRecordingAccess::flattenGroupedRecordings($grouped),
        ], 200);
    }



    private function buildUpcomingClasses($paidCourseIds)

    {

        $upcomingClasses = collect();



        if (!$paidCourseIds->isEmpty()) {

            $zoomMaterialRows = CourseMaterial::query()

                ->with('course')

                ->whereIn('course_id', $paidCourseIds)

                ->where('type', 'zoom')

                ->orderByDesc('scheduled_at')

                ->orderByDesc('created_at')

                ->limit(12)

                ->get();

            $liveMeetingIds = app(\App\Services\ZoomService::class)->fetchLiveMeetingIds();

            $zoomMaterials = $zoomMaterialRows

                ->map(fn (CourseMaterial $m) => CourseMaterialHelper::toLiveClassArray($m, $liveMeetingIds))

                ->filter(fn ($item) => !empty($item['embed_room_path']) || !empty($item['start_time']) || ($item['type'] ?? '') === 'cohort')

                ->sortByDesc(fn ($item) => match ($item['session_status'] ?? '') {

                    'live' => 3,

                    'upcoming' => 2,

                    default => 1,

                })

                ->values();



            $upcomingClasses = $upcomingClasses->merge($zoomMaterials);

        }



        if (Schema::hasTable('livezoom_cohort') && Schema::hasColumn('livezoom_cohort', 'is_active')) {

            $cohortQuery = LiveZoomCohort::query()->where('is_active', true)->orderBy('day_of_week')->limit(4);

            $cohorts = $cohortQuery->get()->map(function (LiveZoomCohort $c) {
                $joinUrl = Schema::hasColumn('livezoom_cohort', 'zoom_link') ? ($c->zoom_link ?? null) : null;
                $sessionStatus = Schema::hasColumn('livezoom_cohort', 'session_status')
                    ? ($c->session_status ?? 'idle')
                    : 'idle';
                $isLive = $sessionStatus === 'live';
                $queueEnabled = Schema::hasTable('livezoom_cohort_queue_entries')
                    && Schema::hasColumn('livezoom_cohort', 'session_status');

                return [
                    'id' => $c->id,
                    'title' => $c->notes ?: 'Weekly live cohort',
                    'course_title' => 'Live cohort schedule',
                    'join_url' => null,
                    'public_join_path' => '/live-cohort/' . $c->id . '/join',
                    'start_time' => Schema::hasColumn('livezoom_cohort', 'session_started_at')
                        ? $c->session_started_at?->toIso8601String()
                        : null,
                    'type' => 'cohort',
                    'is_live_now' => $isLive,
                    'session_status' => $sessionStatus,
                    'queue_enabled' => $queueEnabled,
                    'day_of_week' => $c->day_of_week ?? null,
                    'slot_start_time' => $c->start_time ?? null,
                    'slot_end_time' => $c->end_time ?? null,
                    'timezone' => $c->timezone ?? null,
                ];
            });



            $upcomingClasses = $upcomingClasses->merge($cohorts)->values();

        } else {

            $upcomingClasses = $upcomingClasses->values();

        }



        return $upcomingClasses;

    }



    private function buildNotifications($paidCourseIds): array

    {

        if ($paidCourseIds->isEmpty()) {

            return [];

        }



        $notifications = [];



        $recentZoom = CourseMaterial::query()

            ->with('course:id,title')

            ->whereIn('course_id', $paidCourseIds)

            ->where('type', 'zoom')

            ->where('created_at', '>=', now()->subDays(14))

            ->orderByDesc('created_at')

            ->limit(15)

            ->get();

        $liveMeetingIds = app(ZoomService::class)->fetchLiveMeetingIds();

        foreach ($recentZoom as $material) {

            $state = CourseMaterialHelper::liveSessionState($material, $liveMeetingIds);

            $joinUrl = CourseMaterialHelper::learnerJoinUrl($material);

            $scheduled = CourseMaterialHelper::scheduledAt($material);



            $notifications[] = [

                'id' => 'live-class-' . $material->id,

                'type' => 'live_class',

                'title' => $state['session_status'] === 'live'

                    ? 'Live class in progress'

                    : ($state['session_status'] === 'ended' ? 'Class recording available' : 'Live class scheduled'),

                'message' => ($material->course?->title ?? 'Your course') . ': ' . $material->title,

                'created_at' => $material->created_at?->toIso8601String(),

                'start_time' => $scheduled?->toIso8601String(),

                'course_id' => $material->course_id,

                'material_id' => $material->id,

                'join_url' => null,

                'embed_room_path' => $state['can_join'] ? CourseMaterialHelper::embedRoomPath($material, 0) : null,

                'can_join' => $state['can_join'],

                'session_status' => $state['session_status'],

                'is_past' => $state['is_past'],

                'action_path' => '/dashboard/learner/live-classes',

            ];

        }

        $recentQuizzes = CourseMaterial::query()
            ->with('course:id,title')
            ->whereIn('course_id', $paidCourseIds)
            ->whereIn('type', ['quiz', 'assessment'])
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get()
            ->filter(fn (CourseMaterial $material) => QuizMaterialHelper::isPublished($material));

        foreach ($recentQuizzes as $material) {
            $meta = QuizMaterialHelper::meta($material);
            $publishedAt = $meta['published_at'] ?? $material->updated_at?->toIso8601String();
            if ($publishedAt && Carbon::parse($publishedAt)->lt(now()->subDays(30))) {
                continue;
            }

            $kind = (string) ($meta['assessment_kind'] ?? 'quiz');
            $kindLabel = match ($kind) {
                'exam' => 'Exam',
                'test' => 'Test',
                default => 'Quiz',
            };

            $notifications[] = [
                'id' => 'assessment-' . $material->id,
                'type' => 'assessment',
                'title' => $kindLabel . ' available',
                'message' => ($material->course?->title ?? 'Your course') . ': ' . ($material->title ?? 'Assessment'),
                'created_at' => $publishedAt,
                'course_id' => $material->course_id,
                'material_id' => $material->id,
                'quiz_id' => $material->id,
                'action_path' => '/dashboard/learner/quiz/' . $material->id,
            ];
        }

        usort($notifications, fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));



        return array_slice($notifications, 0, 20);

    }

    private function serializeLearnerShiftRequest($request, \App\Services\EnrollmentStudyShiftService $shiftService): ?array
    {
        if (!$request) {
            return null;
        }

        $requestedShifts = \App\Models\StudyShift::query()
            ->whereIn('id', $request->requested_study_shift_ids ?? [])
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        return [
            'id' => $request->id,
            'status' => $request->status,
            'reason' => $request->reason,
            'created_at' => $request->created_at?->toIso8601String(),
            'requested_shifts' => $shiftService->formatStudyShiftsForApi($requestedShifts),
        ];
    }

}
