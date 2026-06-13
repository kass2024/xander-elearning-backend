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
use App\Support\CourseRevenueCalculator;
use Carbon\Carbon;

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

        $revenueByMonth = CourseRevenueCalculator::revenueByMonth(5);

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
                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'status' => $course->status,
                    'price' => (float) ($course->price ?? 0),
                    'total_enrollments' => (int) $course->total_enrollments,
                    'paid_enrollments' => (int) $course->paid_enrollments,
                    'revenue' => CourseRevenueCalculator::courseRevenue($course),
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

        $stripeRevenue = CourseRevenueCalculator::paymentRevenue();
        $manualRevenue = CourseRevenueCalculator::manualEnrollmentRevenue();

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

        $pendingPayoutRequests = InstructorPayoutRequest::query()
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        $pendingPayoutAmount = (float) InstructorPayoutRequest::query()
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

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
                'totalRevenue' => round($stripeRevenue, 2),
                'stripeRevenue' => round($stripeRevenue, 2),
                'manualRevenue' => round($manualRevenue, 2),
                'pendingInstructors' => $pendingInstructors,
                'pendingCourses' => $pendingCourses,
                'pendingPayments' => $pendingPayments,
                'pendingPayoutRequests' => $pendingPayoutRequests,
                'pendingPayoutAmount' => round($pendingPayoutAmount, 2),
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
