<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CoursePayment;
use App\Models\MeetingRegistration;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminReportsController extends Controller
{
    public function analytics()
    {
        $now = Carbon::now();
        $months = collect(range(5, 0))->map(function ($i) use ($now) {
            return $now->copy()->subMonths($i)->format('Y-m');
        });

        $enrollmentRows = CourseEnrollment::query()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
            ->where('created_at', '>=', $now->copy()->subMonths(5)->startOfMonth())
            ->groupBy('month')
            ->pluck('count', 'month');

        $enrollmentsByMonth = $months->map(fn ($month) => [
            'month' => Carbon::createFromFormat('Y-m', $month)->format('M Y'),
            'count' => (int) ($enrollmentRows[$month] ?? 0),
        ])->values();

        $paymentRows = CoursePayment::query()
            ->selectRaw("DATE_FORMAT(COALESCE(paid_at, created_at), '%Y-%m') as month, SUM(amount_cents) as total_cents")
            ->whereIn('status', ['paid', 'succeeded', 'completed'])
            ->where(function ($q) use ($now) {
                $q->where('paid_at', '>=', $now->copy()->subMonths(5)->startOfMonth())
                    ->orWhere(function ($q2) use ($now) {
                        $q2->whereNull('paid_at')
                            ->where('created_at', '>=', $now->copy()->subMonths(5)->startOfMonth());
                    });
            })
            ->groupBy('month')
            ->pluck('total_cents', 'month');

        $paidEnrollmentRows = CourseEnrollment::query()
            ->join('courses', 'courses.id', '=', 'course_enrollments.course_id')
            ->selectRaw("DATE_FORMAT(course_enrollments.updated_at, '%Y-%m') as month, SUM(COALESCE(courses.price, 0)) as total")
            ->where('course_enrollments.status', 'paid')
            ->where('course_enrollments.updated_at', '>=', $now->copy()->subMonths(5)->startOfMonth())
            ->groupBy('month')
            ->pluck('total', 'month');

        $revenueByMonth = $months->map(function ($month) use ($paymentRows, $paidEnrollmentRows) {
            $stripe = ((int) ($paymentRows[$month] ?? 0)) / 100;
            $manual = (float) ($paidEnrollmentRows[$month] ?? 0);

            return [
                'month' => Carbon::createFromFormat('Y-m', $month)->format('M Y'),
                'amount' => round($stripe + $manual, 2),
            ];
        })->values();

        $instructorPerformance = User::query()
            ->where('role', 'instructor')
            ->withCount('assignedCourses')
            ->orderByDesc('id')
            ->get()
            ->map(function (User $instructor) {
                $courseIds = $instructor->assignedCourses()->pluck('courses.id');
                $students = $courseIds->isEmpty()
                    ? 0
                    : CourseEnrollment::whereIn('course_id', $courseIds)->distinct('student_id')->count('student_id');
                $enrollments = $courseIds->isEmpty()
                    ? 0
                    : CourseEnrollment::whereIn('course_id', $courseIds)->count();

                return [
                    'id' => $instructor->id,
                    'name' => $instructor->name,
                    'email' => $instructor->email,
                    'status' => $instructor->status,
                    'courses_assigned' => $instructor->assigned_courses_count,
                    'total_enrollments' => $enrollments,
                    'unique_students' => $students,
                ];
            })
            ->values();

        $coursePerformance = Course::query()
            ->withCount([
                'enrollments as total_enrollments',
                'enrollments as paid_enrollments' => fn ($q) => $q->where('status', 'paid'),
            ])
            ->orderByDesc('total_enrollments')
            ->get()
            ->map(function (Course $course) {
                $stripeRevenue = CoursePayment::query()
                    ->where('course_id', $course->id)
                    ->whereIn('status', ['paid', 'succeeded', 'completed'])
                    ->sum('amount_cents') / 100;

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'status' => $course->status,
                    'price' => (float) ($course->price ?? 0),
                    'total_enrollments' => (int) $course->total_enrollments,
                    'paid_enrollments' => (int) $course->paid_enrollments,
                    'revenue' => round($stripeRevenue + ((float) ($course->price ?? 0) * (int) $course->paid_enrollments), 2),
                ];
            })
            ->values();

        $studentsByCountry = Student::query()
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

        $stripeRevenue = CoursePayment::query()
            ->whereIn('status', ['paid', 'succeeded', 'completed'])
            ->sum('amount_cents') / 100;

        $manualRevenue = CourseEnrollment::query()
            ->join('courses', 'courses.id', '=', 'course_enrollments.course_id')
            ->where('course_enrollments.status', 'paid')
            ->sum(DB::raw('COALESCE(courses.price, 0)'));

        $pendingInstructors = User::query()
            ->where('role', 'instructor')
            ->whereRaw('LOWER(COALESCE(status, "")) IN (?, ?, ?)', ['pending', 'inactive', ''])
            ->count();

        $pendingCourses = Course::query()
            ->whereRaw('LOWER(COALESCE(status, "")) IN (?, ?)', ['pending', 'draft'])
            ->count();

        $pendingPayments = CoursePayment::query()
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        $meetingStats = [
            'total' => MeetingRegistration::count(),
            'pending' => MeetingRegistration::whereRaw('LOWER(COALESCE(status, "")) = ?', ['pending'])->count(),
            'approved' => MeetingRegistration::whereRaw('LOWER(COALESCE(status, "")) = ?', ['approved'])->count(),
            'rejected' => MeetingRegistration::whereRaw('LOWER(COALESCE(status, "")) = ?', ['rejected'])->count(),
        ];

        return response()->json([
            'summary' => [
                'totalStudents' => Student::count(),
                'totalCourses' => Course::count(),
                'activeCourses' => Course::whereRaw('LOWER(COALESCE(status, "")) = ?', ['active'])->count(),
                'totalInstructors' => User::where('role', 'instructor')->count(),
                'totalEnrollments' => CourseEnrollment::count(),
                'paidEnrollments' => CourseEnrollment::where('status', 'paid')->count(),
                'totalRevenue' => round($stripeRevenue + (float) $manualRevenue, 2),
                'stripeRevenue' => round($stripeRevenue, 2),
                'pendingInstructors' => $pendingInstructors,
                'pendingCourses' => $pendingCourses,
                'pendingPayments' => $pendingPayments,
                'paymentProvider' => 'Stripe',
            ],
            'enrollmentsByMonth' => $enrollmentsByMonth,
            'revenueByMonth' => $revenueByMonth,
            'instructorPerformance' => $instructorPerformance,
            'coursePerformance' => $coursePerformance,
            'studentsByCountry' => $studentsByCountry,
            'marketing' => $meetingStats,
        ], 200);
    }
}
