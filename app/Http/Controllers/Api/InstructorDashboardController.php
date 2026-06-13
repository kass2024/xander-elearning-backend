<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseMaterial;
use App\Models\InstructorPayoutRequest;
use App\Models\User;
use App\Support\CourseMaterialHelper;
use App\Support\CourseRevenueCalculator;
use App\Services\ZoomService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class InstructorDashboardController extends Controller
{
    public function __construct(protected ZoomService $zoom)
    {
    }

    private function sharePercent(): float
    {
        return (float) config('app.instructor_share_percent', 70);
    }

    private function findInstructor(string $email): ?User
    {
        return User::query()
            ->where('email', $email)
            ->where('role', 'instructor')
            ->first();
    }

    private function courseIdsFor(User $instructor)
    {
        return $instructor->assignedCourses()->pluck('courses.id');
    }

    private function courseRevenue(Course $course): float
    {
        return CourseRevenueCalculator::courseRevenue($course);
    }

    public function dashboard(Request $request)
    {
        $email = $request->query('email');
        if (!$email) {
            return response()->json(['message' => 'Email is required'], 400);
        }

        $instructor = $this->findInstructor($email);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $courseIds = $this->courseIdsFor($instructor);
        $share = $this->sharePercent();

        $courses = $instructor->assignedCourses()
            ->withCount([
                'enrollments as enrollments_count',
                'enrollments as paid_enrollments_count' => fn ($q) => $q->where('status', 'paid'),
                'materials as materials_count',
            ])
            ->orderByDesc('id')
            ->get()
            ->map(function (Course $course) use ($share) {
                $uniqueStudents = CourseEnrollment::query()
                    ->where('course_id', $course->id)
                    ->distinct('student_id')
                    ->count('student_id');

                $revenue = $this->courseRevenue($course);

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'description' => $course->description,
                    'status' => $course->status,
                    'price' => (float) ($course->price ?? 0),
                    'duration' => $course->duration,
                    'students_count' => $uniqueStudents,
                    'enrollments_count' => (int) $course->enrollments_count,
                    'paid_enrollments_count' => (int) $course->paid_enrollments_count,
                    'materials_count' => (int) $course->materials_count,
                    'revenue' => $revenue,
                    'earnings' => round($revenue * ($share / 100), 2),
                ];
            })
            ->values();

        $totalRevenue = $courses->sum('revenue');
        $totalEarnings = round($totalRevenue * ($share / 100), 2);

        $paidOut = InstructorPayoutRequest::query()
            ->where('instructor_id', $instructor->id)
            ->whereIn('status', ['approved', 'paid', 'completed'])
            ->sum('amount');

        $pendingPayouts = InstructorPayoutRequest::query()
            ->where('instructor_id', $instructor->id)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        $availableBalance = max(0, round($totalEarnings - $paidOut - $pendingPayouts, 2));

        $now = Carbon::now();
        $months = collect(range(5, 0))->map(fn ($i) => $now->copy()->subMonths($i)->format('Y-m'));

        $enrollmentRows = $courseIds->isEmpty()
            ? collect()
            : CourseEnrollment::query()
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->whereIn('course_id', $courseIds)
                ->where('created_at', '>=', $now->copy()->subMonths(5)->startOfMonth())
                ->groupBy('month')
                ->pluck('count', 'month');

        $enrollmentsByMonth = $months->map(fn ($month) => [
            'month' => Carbon::createFromFormat('Y-m', $month)->format('M Y'),
            'count' => (int) ($enrollmentRows[$month] ?? 0),
        ])->values();

        $courseIdList = $courseIds->all();
        $since = $now->copy()->subMonths(5)->startOfMonth();

        $paymentRows = $courseIds->isEmpty()
            ? collect()
            : CourseRevenueCalculator::monthlyPaymentRevenue($since, $courseIdList);

        $earningsByMonth = $months->map(function ($month) use ($paymentRows, $share) {
            $revenue = (float) ($paymentRows[$month] ?? 0);

            return [
                'month' => Carbon::createFromFormat('Y-m', $month)->format('M Y'),
                'revenue' => round($revenue, 2),
                'earnings' => round($revenue * ($share / 100), 2),
            ];
        })->values();

        $recentEnrollments = $courseIds->isEmpty()
            ? collect()
            : CourseEnrollment::query()
                ->with(['student', 'course'])
                ->whereIn('course_id', $courseIds)
                ->orderByDesc('created_at')
                ->limit(8)
                ->get()
                ->map(function (CourseEnrollment $enrollment) {
                    $student = $enrollment->student;
                    $name = $student
                        ? ($student->name ?? trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? '')))
                        : 'Student';

                    return [
                        'type' => 'enrollment',
                        'message' => trim($name) . ' enrolled in ' . ($enrollment->course->title ?? 'a course'),
                        'status' => $enrollment->status,
                        'at' => $enrollment->created_at?->toIso8601String(),
                    ];
                })
                ->values();

        $upcomingClasses = $courseIds->isEmpty()
            ? collect()
            : CourseMaterial::query()
                ->with('course')
                ->whereIn('course_id', $courseIds)
                ->where('type', 'zoom')
                ->orderByDesc('created_at')
                ->limit(6)
                ->get()
                ->map(fn (CourseMaterial $material) => [
                    'id' => $material->id,
                    'title' => $material->title,
                    'course_id' => $material->course_id,
                    'course_title' => $material->course->title ?? 'Course',
                    'join_url' => CourseMaterialHelper::learnerJoinUrl($material),
                    'start_url' => is_array($material->metadata) ? ($material->metadata['start_url'] ?? null) : null,
                    'scheduled_at' => CourseMaterialHelper::scheduledAt($material)?->toIso8601String(),
                    'created_at' => $material->created_at?->toIso8601String(),
                ])
                ->values();

        $quizCount = $courseIds->isEmpty()
            ? 0
            : CourseMaterial::query()
                ->whereIn('course_id', $courseIds)
                ->whereIn('type', ['quiz', 'assessment'])
                ->count();

        $payoutRequests = InstructorPayoutRequest::query()
            ->where('instructor_id', $instructor->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (InstructorPayoutRequest $row) => [
                'id' => $row->id,
                'amount' => (float) $row->amount,
                'status' => $row->status,
                'notes' => $row->notes,
                'created_at' => $row->created_at?->toIso8601String(),
            ])
            ->values();

        $uniqueStudents = $courseIds->isEmpty()
            ? 0
            : CourseEnrollment::whereIn('course_id', $courseIds)->distinct('student_id')->count('student_id');

        $totalEnrollments = $courseIds->isEmpty()
            ? 0
            : CourseEnrollment::whereIn('course_id', $courseIds)->count();

        $paidEnrollments = $courseIds->isEmpty()
            ? 0
            : CourseEnrollment::whereIn('course_id', $courseIds)->where('status', 'paid')->count();

        $materialsCount = $courseIds->isEmpty()
            ? 0
            : CourseMaterial::whereIn('course_id', $courseIds)->count();

        $activeCourses = $courses->filter(
            fn ($c) => strtolower((string) ($c['status'] ?? '')) === 'active'
        )->count();

        return response()->json([
            'instructor' => [
                'id' => $instructor->id,
                'name' => $instructor->name,
                'email' => $instructor->email,
                'status' => $instructor->status,
            ],
            'summary' => [
                'assignedCourses' => $courses->count(),
                'activeCourses' => $activeCourses,
                'totalStudents' => $uniqueStudents,
                'totalEnrollments' => $totalEnrollments,
                'paidEnrollments' => $paidEnrollments,
                'materialsCount' => $materialsCount,
                'quizCount' => $quizCount,
                'upcomingClasses' => $upcomingClasses->count(),
                'totalRevenue' => round($totalRevenue, 2),
                'totalEarnings' => $totalEarnings,
                'availableBalance' => $availableBalance,
                'pendingPayouts' => round((float) $pendingPayouts, 2),
                'paidOut' => round((float) $paidOut, 2),
                'instructorSharePercent' => $share,
            ],
            'courses' => $courses,
            'enrollmentsByMonth' => $enrollmentsByMonth,
            'earningsByMonth' => $earningsByMonth,
            'recentActivity' => $recentEnrollments,
            'upcomingClasses' => $upcomingClasses,
            'payoutRequests' => $payoutRequests,
        ], 200);
    }

    public function liveClasses(Request $request)
    {
        $email = $request->query('email');
        $courseId = $request->query('course_id');

        if (!$email) {
            return response()->json(['message' => 'Email is required'], 400);
        }

        $instructor = $this->findInstructor($email);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $courseIds = $this->courseIdsFor($instructor);

        $courses = $instructor->assignedCourses()
            ->withCount([
                'enrollments as paid_enrollments_count' => fn ($q) => $q->whereIn('status', ['paid', 'completed']),
            ])
            ->orderBy('title')
            ->get()
            ->map(fn (Course $course) => [
                'id' => $course->id,
                'title' => $course->title,
                'description' => $course->description,
                'status' => $course->status,
                'duration' => $course->duration,
                'paid_enrollments_count' => (int) ($course->paid_enrollments_count ?? 0),
            ])
            ->values();

        $sessionsQuery = CourseMaterial::query()
            ->with('course:id,title')
            ->where('type', 'zoom')
            ->orderByDesc('scheduled_at')
            ->orderByDesc('created_at');

        if ($courseId) {
            $sessionsQuery->where('course_id', (int) $courseId);
        } elseif (!$courseIds->isEmpty()) {
            $sessionsQuery->whereIn('course_id', $courseIds);
        } else {
            $sessionsQuery->whereRaw('1 = 0');
        }

        $sessions = $sessionsQuery
            ->limit($courseId ? 20 : 12)
            ->get()
            ->map(fn (CourseMaterial $material) => [
                'id' => $material->id,
                'title' => $material->title,
                'course_id' => $material->course_id,
                'course_title' => $material->course?->title,
                'description' => $material->description,
                'join_url' => CourseMaterialHelper::learnerJoinUrl($material),
                'start_url' => is_array($material->metadata) ? ($material->metadata['start_url'] ?? null) : null,
                'scheduled_at' => CourseMaterialHelper::scheduledAt($material)?->toIso8601String(),
                'created_at' => $material->created_at?->toIso8601String(),
            ])
            ->values();

        $zoomConfigured = !empty(config('services.zoom.account_id'))
            && !empty(config('services.zoom.client_id'))
            && !empty(config('services.zoom.client_secret'));

        return response()->json([
            'instructor' => [
                'id' => $instructor->id,
                'name' => $instructor->name,
                'email' => $instructor->email,
            ],
            'zoom' => [
                'configured' => $zoomConfigured,
                'host_user_id' => config('services.zoom.host_user_id', 'me'),
            ],
            'courses' => $courses,
            'sessions' => $sessions,
        ], 200);
    }

    public function startLiveSession(Request $request, CourseMaterial $material)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
            'enable_recording' => 'nullable|boolean',
        ]);

        $instructor = $this->findInstructor($data['instructor_email']);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        if (strtolower((string) $material->type) !== 'zoom') {
            return response()->json(['message' => 'This material is not a live class session.'], 422);
        }

        $courseIds = $this->courseIdsFor($instructor);
        if (!$courseIds->contains($material->course_id)) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $enableRecording = (bool) ($data['enable_recording'] ?? false);
        $meetingId = CourseMaterialHelper::meetingId($material);
        $meta = is_array($material->metadata) ? $material->metadata : [];

        if ($enableRecording && $meetingId && $this->zoom->canManageMeetingViaApi($meetingId)) {
            $result = $this->zoom->setMeetingAutoRecording($meetingId, true);
            if ($result === null) {
                return response()->json([
                    'message' => 'Unable to contact Zoom to enable cloud recording.',
                ], 503);
            }
            if (!empty($result['error'])) {
                return response()->json([
                    'message' => 'Zoom rejected cloud recording for this live class.',
                    'details' => $result['body'] ?? null,
                ], 502);
            }
        }

        if ($enableRecording) {
            $meta['recording_enabled'] = true;
            $material->metadata = $meta;
            $material->save();
        }

        CourseMaterialHelper::markSessionStarted($material);

        return response()->json([
            'message' => $enableRecording
                ? 'Live session started with cloud recording enabled for paid learners.'
                : 'Live session marked as started. Learners can join now.',
            'recording_enabled' => (bool) ($meta['recording_enabled'] ?? false),
            'session' => CourseMaterialHelper::toLiveClassArray($material),
        ], 200);
    }

    public function students(Request $request)
    {
        $email = $request->query('email');
        if (!$email) {
            return response()->json(['message' => 'Email is required'], 400);
        }

        $instructor = $this->findInstructor($email);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $courseIds = $this->courseIdsFor($instructor);
        if ($courseIds->isEmpty()) {
            return response()->json(['students' => [], 'courses' => []], 200);
        }

        $courses = Course::query()
            ->whereIn('id', $courseIds)
            ->orderBy('title')
            ->get(['id', 'title']);

        $rows = CourseEnrollment::query()
            ->with(['student', 'course'])
            ->whereIn('course_id', $courseIds)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (CourseEnrollment $enrollment) {
                $student = $enrollment->student;
                if (!$student) {
                    return null;
                }

                return [
                    'enrollment_id' => $enrollment->id,
                    'student_id' => $student->id,
                    'name' => $student->name ?? trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? '')),
                    'email' => $student->email,
                    'country' => $student->country ?? null,
                    'course_id' => $enrollment->course_id,
                    'course_title' => $enrollment->course->title ?? 'Course',
                    'status' => $enrollment->status,
                    'enrolled_at' => $enrollment->created_at?->toIso8601String(),
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'courses' => $courses,
            'students' => $rows,
        ], 200);
    }

    public function createCourse(Request $request)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'duration' => 'nullable|string|max:255',
            'requirements' => 'nullable|string',
        ]);

        $instructor = $this->findInstructor($data['instructor_email']);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $course = Course::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'] ?? 0,
            'duration' => $data['duration'] ?? null,
            'requirements' => $data['requirements'] ?? null,
            'status' => 'Pending',
        ]);

        $instructor->assignedCourses()->syncWithoutDetaching([$course->id]);

        return response()->json([
            'message' => 'Course submitted for admin approval.',
            'course' => $course,
        ], 201);
    }

    public function payoutRequests(Request $request)
    {
        $email = $request->query('email');
        if (!$email) {
            return response()->json(['message' => 'Email is required'], 400);
        }

        $instructor = $this->findInstructor($email);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $rows = InstructorPayoutRequest::query()
            ->where('instructor_id', $instructor->id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['payoutRequests' => $rows], 200);
    }

    public function requestPayout(Request $request)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
            'amount' => 'required|numeric|min:1',
            'notes' => 'nullable|string|max:500',
        ]);

        $instructor = $this->findInstructor($data['instructor_email']);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $dashboard = $this->buildBalanceSnapshot($instructor);
        $available = $dashboard['availableBalance'];

        if ((float) $data['amount'] > $available) {
            return response()->json([
                'message' => 'Requested amount exceeds available balance ($' . number_format($available, 2) . ').',
            ], 422);
        }

        $row = InstructorPayoutRequest::create([
            'instructor_id' => $instructor->id,
            'amount' => round((float) $data['amount'], 2),
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Payout request submitted. Admin will process it shortly.',
            'payoutRequest' => $row,
        ], 201);
    }

    public function quizzes(Request $request)
    {
        $email = $request->query('email');
        if (!$email) {
            return response()->json(['message' => 'Email is required'], 400);
        }

        $instructor = $this->findInstructor($email);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $courseIds = $this->courseIdsFor($instructor);
        if ($courseIds->isEmpty()) {
            return response()->json(['quizzes' => [], 'courses' => []], 200);
        }

        $courses = Course::query()->whereIn('id', $courseIds)->orderBy('title')->get(['id', 'title']);

        $quizzes = CourseMaterial::query()
            ->with('course')
            ->whereIn('course_id', $courseIds)
            ->whereIn('type', ['quiz', 'assessment'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (CourseMaterial $m) => [
                'id' => $m->id,
                'course_id' => $m->course_id,
                'course_title' => $m->course->title ?? 'Course',
                'title' => $m->title,
                'description' => $m->description,
                'type' => $m->type,
                'resource_url' => $m->resource_url,
                'created_at' => $m->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'courses' => $courses,
            'quizzes' => $quizzes,
        ], 200);
    }

    public function storeQuiz(Request $request)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
            'course_id' => 'required|integer|exists:courses,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'resource_url' => 'nullable|url|max:2048',
        ]);

        $instructor = $this->findInstructor($data['instructor_email']);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $assigned = $instructor->assignedCourses()->where('courses.id', $data['course_id'])->exists();
        if (!$assigned) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $quiz = CourseMaterial::create([
            'course_id' => $data['course_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => 'quiz',
            'resource_url' => $data['resource_url'] ?? null,
            'sort_order' => 0,
        ]);

        return response()->json([
            'message' => 'Quiz created.',
            'quiz' => $quiz,
        ], 201);
    }

    private function buildBalanceSnapshot(User $instructor): array
    {
        $courseIds = $this->courseIdsFor($instructor);
        $share = $this->sharePercent();

        $totalRevenue = 0.0;
        if (!$courseIds->isEmpty()) {
            $courses = Course::whereIn('id', $courseIds)->get();
            foreach ($courses as $course) {
                $totalRevenue += $this->courseRevenue($course);
            }
        }

        $totalEarnings = round($totalRevenue * ($share / 100), 2);
        $paidOut = (float) InstructorPayoutRequest::query()
            ->where('instructor_id', $instructor->id)
            ->whereIn('status', ['approved', 'paid', 'completed'])
            ->sum('amount');
        $pendingPayouts = (float) InstructorPayoutRequest::query()
            ->where('instructor_id', $instructor->id)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        return [
            'totalEarnings' => $totalEarnings,
            'availableBalance' => max(0, round($totalEarnings - $paidOut - $pendingPayouts, 2)),
        ];
    }
}
