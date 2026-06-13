<?php

namespace App\Support;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CoursePayment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CourseRevenueCalculator
{
    /**
     * @return list<string>
     */
    public static function paidPaymentStatuses(): array
    {
        return ['paid', 'succeeded', 'completed'];
    }

    /**
     * @return list<string>
     */
    public static function paidEnrollmentStatuses(): array
    {
        return ['paid', 'completed'];
    }

    /**
     * @param  list<int>|null  $courseIds
     */
    public static function paidPaymentsQuery(?array $courseIds = null): Builder
    {
        $query = CoursePayment::query()->whereIn('status', self::paidPaymentStatuses());

        if ($courseIds !== null) {
            $query->whereIn('course_id', $courseIds);
        }

        return $query;
    }

    /**
     * Sum of successful payment records (Stripe and other providers).
     *
     * @param  list<int>|null  $courseIds
     */
    public static function paymentRevenue(?array $courseIds = null): float
    {
        return (float) self::paidPaymentsQuery($courseIds)->sum('amount_cents') / 100;
    }

    /**
     * Paid enrollments that were not settled through a successful payment record
     * (e.g. admin mark-paid without Stripe).
     *
     * @param  list<int>|null  $courseIds
     */
    public static function manualEnrollmentRevenueQuery(?array $courseIds = null): Builder
    {
        $query = CourseEnrollment::query()
            ->join('courses', 'courses.id', '=', 'course_enrollments.course_id')
            ->whereIn('course_enrollments.status', self::paidEnrollmentStatuses())
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('course_payments')
                    ->whereColumn('course_payments.student_id', 'course_enrollments.student_id')
                    ->whereColumn('course_payments.course_id', 'course_enrollments.course_id')
                    ->whereIn('course_payments.status', self::paidPaymentStatuses());
            });

        if ($courseIds !== null) {
            $query->whereIn('course_enrollments.course_id', $courseIds);
        }

        return $query;
    }

    /**
     * @param  list<int>|null  $courseIds
     */
    public static function manualEnrollmentRevenue(?array $courseIds = null): float
    {
        return (float) self::manualEnrollmentRevenueQuery($courseIds)
            ->sum(DB::raw('COALESCE(courses.price, 0)'));
    }

    /**
     * Paid checkout revenue for one course (matches Payment Management "paid" rows).
     */
    public static function courseRevenue(Course|int $course): float
    {
        $courseId = $course instanceof Course ? $course->id : $course;

        return round(self::paymentRevenue([$courseId]), 2);
    }

    /**
     * Platform revenue = sum of paid payment records only (excludes pending/failed).
     *
     * @param  list<int>|null  $courseIds
     */
    public static function totalRevenue(?array $courseIds = null): float
    {
        return round(self::paymentRevenue($courseIds), 2);
    }

    /**
     * @param  list<int>|null  $courseIds
     * @return Collection<string, float> month key (Y-m) => amount
     */
    public static function monthlyPaymentRevenue(Carbon $since, ?array $courseIds = null): Collection
    {
        $query = self::paidPaymentsQuery($courseIds)
            ->selectRaw("DATE_FORMAT(COALESCE(paid_at, created_at), '%Y-%m') as month, SUM(amount_cents) as total_cents")
            ->where(function ($q) use ($since) {
                $q->where('paid_at', '>=', $since)
                    ->orWhere(function ($q2) use ($since) {
                        $q2->whereNull('paid_at')
                            ->where('created_at', '>=', $since);
                    });
            })
            ->groupBy('month');

        return $query->pluck('total_cents', 'month')->map(fn ($cents) => ((int) $cents) / 100);
    }

    /**
     * @param  list<int>|null  $courseIds
     * @return Collection<string, float> month key (Y-m) => amount
     */
    public static function monthlyManualEnrollmentRevenue(Carbon $since, ?array $courseIds = null): Collection
    {
        $query = self::manualEnrollmentRevenueQuery($courseIds)
            ->selectRaw("DATE_FORMAT(course_enrollments.updated_at, '%Y-%m') as month, SUM(COALESCE(courses.price, 0)) as total")
            ->where('course_enrollments.updated_at', '>=', $since)
            ->groupBy('month');

        return $query->pluck('total', 'month')->map(fn ($amount) => (float) $amount);
    }

    /**
     * @param  list<int>|null  $courseIds
     * @return Collection<int, array{month: string, amount: float}>
     */
    public static function revenueByMonth(int $monthsBack = 5, ?array $courseIds = null): Collection
    {
        $now = Carbon::now();
        $since = $now->copy()->subMonths($monthsBack)->startOfMonth();
        $months = collect(range($monthsBack, 0))->map(fn ($i) => $now->copy()->subMonths($i)->format('Y-m'));

        $paymentRows = self::monthlyPaymentRevenue($since, $courseIds);

        return $months->map(function ($month) use ($paymentRows) {
            return [
                'month' => Carbon::createFromFormat('Y-m', $month)->format('M Y'),
                'amount' => round((float) ($paymentRows[$month] ?? 0), 2),
            ];
        })->values();
    }
}
