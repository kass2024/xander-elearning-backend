<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CoursePayment;
use App\Models\InstructorPayoutRequest;
use App\Models\MeetingRegistration;
use App\Models\Student;
use App\Models\User;
use App\Support\ApiListCache;
use App\Support\CourseRevenueCalculator;
use App\Support\PlatformTenantScope;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AdminReportsController extends Controller
{
    public function analytics(Request $request)
    {
        $tenantId = PlatformTenantScope::resolveTenantId($request);

        if ($tenantId !== null) {
            return response()->json($this->buildAnalyticsPayload($tenantId), 200);
        }

        $payload = ApiListCache::remember('analytics', 'admin_dashboard', 180, function () {
            return $this->buildAnalyticsPayload();
        });

        return response()->json($payload, 200);
    }

    protected function buildAnalyticsPayload(?int $tenantId = null): array
    {
        $tenantCourseIds = $tenantId !== null
            ? PlatformTenantScope::tenantCourseIds($tenantId)
            : null;
        $now = Carbon::now();
        $months = collect(range(5, 0))->map(function ($i) use ($now) {
            return $now->copy()->subMonths($i)->format('Y-m');
        });

        $enrollmentRows = CourseEnrollment::query()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
            ->where('created_at', '>=', $now->copy()->subMonths(5)->startOfMonth())
            ->when($tenantCourseIds !== null, fn ($q) => $q->whereIn('course_id', $tenantCourseIds ?: [-1]))
            ->groupBy('month')
            ->pluck('count', 'month');

        $enrollmentsByMonth = $months->map(fn ($month) => [
            'month' => Carbon::createFromFormat('Y-m', $month)->format('M Y'),
            'count' => (int) ($enrollmentRows[$month] ?? 0),
        ])->values();

        $revenueByMonth = CourseRevenueCalculator::revenueByMonth(5, $tenantCourseIds);
        $sharePercent = (float) config('app.instructor_share_percent', 70);
        $platformSharePercent = round(100 - $sharePercent, 2);

        $revenueByMonthSplit = $revenueByMonth->map(function (array $row) use ($sharePercent, $platformSharePercent) {
            $total = (float) ($row['amount'] ?? 0);

            return [
                'month' => $row['month'],
                'amount' => $total,
                'instructor_earnings' => round($total * ($sharePercent / 100), 2),
                'platform_earnings' => round($total * ($platformSharePercent / 100), 2),
            ];
        })->values();

        $instructorPerformance = User::query()
            ->where('role', 'instructor')
            ->when($tenantId !== null, fn ($q) => $q->where('platform_institution_id', $tenantId))
            ->withCount('assignedCourses')
            ->orderByDesc('id')
            ->get()
            ->map(function (User $instructor) use ($sharePercent, $platformSharePercent) {
                $courseIds = $instructor->assignedCourses()->pluck('courses.id');
                $students = $courseIds->isEmpty()
                    ? 0
                    : CourseEnrollment::whereIn('course_id', $courseIds)->distinct('student_id')->count('student_id');
                $enrollments = $courseIds->isEmpty()
                    ? 0
                    : CourseEnrollment::whereIn('course_id', $courseIds)->count();

                $totalRevenue = $courseIds->isEmpty()
                    ? 0.0
                    : CourseRevenueCalculator::paymentRevenue($courseIds->all());
                $instructorEarnings = round($totalRevenue * ($sharePercent / 100), 2);
                $platformEarnings = round($totalRevenue * ($platformSharePercent / 100), 2);

                $paidOut = (float) InstructorPayoutRequest::query()
                    ->where('instructor_id', $instructor->id)
                    ->whereIn('status', ['approved', 'paid', 'completed'])
                    ->sum('amount');
                $pendingPayout = (float) InstructorPayoutRequest::query()
                    ->where('instructor_id', $instructor->id)
                    ->whereIn('status', ['pending', 'processing'])
                    ->sum('amount');

                return [
                    'id' => $instructor->id,
                    'name' => $instructor->name,
                    'email' => $instructor->email,
                    'status' => $instructor->status,
                    'courses_assigned' => $instructor->assigned_courses_count,
                    'total_enrollments' => $enrollments,
                    'unique_students' => $students,
                    'total_revenue' => round($totalRevenue, 2),
                    'instructor_earnings' => $instructorEarnings,
                    'platform_earnings' => $platformEarnings,
                    'paid_out' => round($paidOut, 2),
                    'pending_payout' => round($pendingPayout, 2),
                    'available_balance' => max(0, round($instructorEarnings - $paidOut - $pendingPayout, 2)),
                ];
            })
            ->values();

        $coursePerformance = Course::query()
            ->when($tenantId !== null, fn ($q) => $q->where('platform_institution_id', $tenantId))
            ->with(['instructors:id,name,email'])
            ->withCount([
                'enrollments as total_enrollments',
                'enrollments as paid_enrollments' => fn ($q) => $q->where('status', 'paid'),
            ])
            ->orderByDesc('total_enrollments')
            ->get()
            ->map(function (Course $course) use ($sharePercent, $platformSharePercent) {
                $revenue = CourseRevenueCalculator::courseRevenue($course);
                $instructorNames = $course->instructors->pluck('name')->filter()->values()->all();

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'status' => $course->status,
                    'price' => (float) ($course->price ?? 0),
                    'total_enrollments' => (int) $course->total_enrollments,
                    'paid_enrollments' => (int) $course->paid_enrollments,
                    'revenue' => $revenue,
                    'instructor_earnings' => round($revenue * ($sharePercent / 100), 2),
                    'platform_earnings' => round($revenue * ($platformSharePercent / 100), 2),
                    'instructor_names' => $instructorNames,
                    'instructor_label' => $instructorNames ? implode(', ', $instructorNames) : 'Unassigned',
                ];
            })
            ->values();

        $studentsByCountry = Student::query()
            ->when($tenantId !== null, fn ($q) => $q->where('platform_institution_id', $tenantId))
            ->selectRaw("COALESCE(NULLIF(TRIM(country), ''), 'Unknown') as country, COUNT(*) as count")
            ->groupBy('country')
            ->orderByDesc('count')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'country' => $row->country,
                'count' => (int) $row->count,
            ])
            ->values();

        $stripeRevenue = CourseRevenueCalculator::paymentRevenue($tenantCourseIds);
        $manualRevenue = CourseRevenueCalculator::manualEnrollmentRevenue($tenantCourseIds);
        $instructorEarningsTotal = round($stripeRevenue * ($sharePercent / 100), 2);
        $platformEarningsTotal = round($stripeRevenue * ($platformSharePercent / 100), 2);

        $pendingInstructors = User::query()
            ->where('role', 'instructor')
            ->when($tenantId !== null, fn ($q) => $q->where('platform_institution_id', $tenantId))
            ->whereRaw('LOWER(COALESCE(status, "")) IN (?, ?, ?)', ['pending', 'inactive', ''])
            ->count();

        $pendingCourses = Course::query()
            ->when($tenantId !== null, fn ($q) => $q->where('platform_institution_id', $tenantId))
            ->whereRaw('LOWER(COALESCE(status, "")) IN (?, ?)', ['pending', 'draft'])
            ->count();

        $pendingPayments = CoursePayment::query()
            ->when($tenantCourseIds !== null, fn ($q) => $q->whereIn('course_id', $tenantCourseIds ?: [-1]))
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        $pendingPayoutQuery = InstructorPayoutRequest::query()
            ->when($tenantId !== null, function ($q) use ($tenantId) {
                $q->whereHas('instructor', fn ($iq) => $iq->where('platform_institution_id', $tenantId));
            });

        $pendingPayoutRequests = (clone $pendingPayoutQuery)
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        $pendingPayoutAmount = (float) (clone $pendingPayoutQuery)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        $meetingStats = $tenantId !== null
            ? ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0]
            : [
                'total' => MeetingRegistration::count(),
                'pending' => MeetingRegistration::whereRaw('LOWER(COALESCE(status, "")) = ?', ['pending'])->count(),
                'approved' => MeetingRegistration::whereRaw('LOWER(COALESCE(status, "")) = ?', ['approved'])->count(),
                'rejected' => MeetingRegistration::whereRaw('LOWER(COALESCE(status, "")) = ?', ['rejected'])->count(),
            ];

        $studentQuery = Student::query();
        if ($tenantId !== null) {
            $studentQuery->where('platform_institution_id', $tenantId);
        }

        $courseQuery = Course::query();
        if ($tenantId !== null) {
            $courseQuery->where('platform_institution_id', $tenantId);
        }

        $instructorQuery = User::query()->where('role', 'instructor');
        if ($tenantId !== null) {
            $instructorQuery->where('platform_institution_id', $tenantId);
        }

        $enrollmentQuery = CourseEnrollment::query();
        if ($tenantCourseIds !== null) {
            $enrollmentQuery->whereIn('course_id', $tenantCourseIds ?: [-1]);
        }

        return [
            'summary' => [
                'totalStudents' => $studentQuery->count(),
                'totalCourses' => $courseQuery->count(),
                'activeCourses' => (clone $courseQuery)->whereRaw('LOWER(COALESCE(status, "")) = ?', ['active'])->count(),
                'totalInstructors' => $instructorQuery->count(),
                'totalEnrollments' => $enrollmentQuery->count(),
                'paidEnrollments' => (clone $enrollmentQuery)->where('status', 'paid')->count(),
                'totalRevenue' => round($stripeRevenue, 2),
                'stripeRevenue' => round($stripeRevenue, 2),
                'manualRevenue' => round($manualRevenue, 2),
                'instructorEarnings' => $instructorEarningsTotal,
                'platformEarnings' => $platformEarningsTotal,
                'instructorSharePercent' => $sharePercent,
                'platformSharePercent' => $platformSharePercent,
                'pendingInstructors' => $pendingInstructors,
                'pendingCourses' => $pendingCourses,
                'pendingPayments' => $pendingPayments,
                'pendingPayoutRequests' => $pendingPayoutRequests,
                'pendingPayoutAmount' => round($pendingPayoutAmount, 2),
                'paymentProvider' => 'Stripe',
            ],
            'enrollmentsByMonth' => $enrollmentsByMonth,
            'revenueByMonth' => $revenueByMonth,
            'revenueByMonthSplit' => $revenueByMonthSplit,
            'instructorPerformance' => $instructorPerformance,
            'coursePerformance' => $coursePerformance,
            'studentsByCountry' => $studentsByCountry,
            'marketing' => $meetingStats,
        ];
    }
}
