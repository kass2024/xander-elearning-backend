<?php

namespace App\Support;

class EnrollmentStatusHelper
{
    /** Statuses that grant full course resource access (materials, quizzes, live classes). */
    public const ACCESS_STATUSES = ['approved', 'paid', 'completed'];

    /** Statuses where payment has been recorded. */
    public const PAID_STATUSES = ['paid', 'completed'];

    public static function normalize(?string $status): string
    {
        return strtolower(trim((string) $status));
    }

    public static function hasCourseAccess(?string $status): bool
    {
        return in_array(self::normalize($status), self::ACCESS_STATUSES, true);
    }

    public static function isPaid(?string $status): bool
    {
        return in_array(self::normalize($status), self::PAID_STATUSES, true);
    }

    public static function isPendingApproval(?string $status): bool
    {
        $s = self::normalize($status);

        return in_array($s, ['enrolled', 'applied', 'waiting approval'], true);
    }

    public static function canPay(?string $status): bool
    {
        return self::normalize($status) === 'approved';
    }

    public static function isRejected(?string $status): bool
    {
        return self::normalize($status) === 'rejected';
    }

    /** Learner may open the course guide page (any application except rejected). */
    public static function canViewCourseGuide(?string $status): bool
    {
        if (self::isRejected($status)) {
            return false;
        }

        $s = self::normalize($status);

        return in_array($s, ['enrolled', 'applied', 'waiting approval', 'approved', 'paid', 'completed', 'active'], true);
    }

    public static function accessStatuses(): array
    {
        return self::ACCESS_STATUSES;
    }
}
