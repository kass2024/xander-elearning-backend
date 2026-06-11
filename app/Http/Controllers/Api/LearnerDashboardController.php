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

            ->with(['course.materials'])

            ->where('student_id', $student->id)

            ->orderByDesc('updated_at')

            ->get();



        $enrolledCourseIds = $enrollments->pluck('course_id')->filter()->unique()->values();



        $paidOrCompleted = $enrollments->filter(

            fn (CourseEnrollment $e) => in_array(strtolower((string) $e->status), ['paid', 'completed'], true)

        );



        $paidCourseIds = $paidOrCompleted->pluck('course_id')->filter()->unique()->values();



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



        $enrolledCourses = $enrollments->map(function (CourseEnrollment $enrollment) {

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

                'paid' => 55,

                'enrolled' => 15,

                default => 5,

            };



            return [

                'id' => $course->id,

                'title' => $course->title,

                'description' => $course->description,

                'status' => $enrollment->status,

                'level' => $enrollment->level,

                'price' => (float) ($course->price ?? 0),

                'progress_percent' => $progress,

                'materials_count' => $materials->count(),

                'videos_count' => $videos,

                'documents_count' => $documents,

                'quizzes_count' => $quizzes,

                'enrolled_at' => $enrollment->created_at?->toIso8601String(),

            ];

        })->filter()->values();



        $allMaterials = $paidCourseIds->isEmpty()

            ? collect()

            : CourseMaterial::query()->whereIn('course_id', $paidCourseIds)->get();



        $learningFeatures = [

            'hd_video_lessons' => $allMaterials->filter(fn ($m) => CourseMaterialHelper::materialKind($m) === 'video')->count(),

            'downloadable_resources' => $allMaterials->filter(fn ($m) => CourseMaterialHelper::materialKind($m) === 'document')->count(),

            'quizzes_assessments' => $allMaterials->filter(fn ($m) => in_array(CourseMaterialHelper::materialKind($m), ['quiz', 'assessment'], true))->count(),

            'mock_exams' => $allMaterials->filter(fn ($m) => str_contains(strtolower((string) $m->title), 'mock'))->count(),

            'live_classes' => $allMaterials->filter(fn ($m) => CourseMaterialHelper::materialKind($m) === 'zoom')->count(),

        ];



        $upcomingClasses = $this->buildUpcomingClasses($paidCourseIds);

        $notifications = $this->buildNotifications($paidCourseIds);



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



        $enrollmentStatuses = $enrollments->keyBy('course_id')->map(fn ($e) => $e->status);



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

                'active_courses' => $paidOrCompleted->count(),

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

            ->whereIn('status', ['paid', 'completed'])

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



        $courseTitles = CourseEnrollment::query()

            ->with('course:id,title')

            ->where('student_id', $student->id)

            ->whereIn('status', ['paid', 'completed'])

            ->get()

            ->map(fn (CourseEnrollment $e) => strtolower((string) ($e->course?->title ?? '')))

            ->filter()

            ->unique()

            ->values()

            ->all();



        if (empty($courseTitles)) {

            return response()->json(['recordings' => []], 200);

        }



        $data = $this->zoom->listRecordings('me');

        if ($data === null) {

            return response()->json(['message' => 'Unable to contact Zoom for recordings'], 503);

        }



        $items = [];

        foreach (($data['meetings'] ?? []) as $meeting) {

            $topic = strtolower((string) ($meeting['topic'] ?? ''));

            $matchesCourse = false;

            foreach ($courseTitles as $title) {

                if ($title !== '' && (str_contains($topic, $title) || str_contains($title, $topic))) {

                    $matchesCourse = true;

                    break;

                }

            }



            if (!$matchesCourse) {

                continue;

            }



            $files = [];

            foreach (($meeting['recording_files'] ?? []) as $file) {

                $files[] = [

                    'id' => $file['id'] ?? null,

                    'recording_type' => $file['recording_type'] ?? null,

                    'file_type' => $file['file_type'] ?? null,

                    'play_url' => $file['play_url'] ?? null,

                    'download_url' => $file['download_url'] ?? null,

                ];

            }



            if (empty($files)) {

                continue;

            }



            $items[] = [

                'uuid' => $meeting['uuid'] ?? null,

                'id' => $meeting['id'] ?? null,

                'topic' => $meeting['topic'] ?? 'Recorded class',

                'start_time' => $meeting['start_time'] ?? null,

                'duration' => $meeting['duration'] ?? null,

                'files' => $files,

            ];

        }



        return response()->json(['recordings' => $items], 200);

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

                ->filter(fn ($item) => !empty($item['join_url']) || !empty($item['start_time']))

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

                $joinUrl = null;

                if (Schema::hasColumn('livezoom_cohort', 'zoom_link')) {

                    $joinUrl = $c->zoom_link ?? null;

                }



                return [

                    'id' => $c->id,

                    'title' => $c->notes ?: 'Weekly live cohort',

                    'course_title' => 'Live cohort schedule',

                    'join_url' => $joinUrl,

                    'start_time' => null,

                    'type' => 'cohort',

                    'is_live_now' => false,

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

                'join_url' => $state['can_join'] ? $joinUrl : null,

                'can_join' => $state['can_join'],

                'session_status' => $state['session_status'],

                'is_past' => $state['is_past'],

                'action_path' => '/dashboard/learner/live-classes',

            ];

        }



        usort($notifications, fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));



        return array_slice($notifications, 0, 20);

    }

}


