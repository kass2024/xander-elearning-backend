<?php

namespace App\Support;

use App\Models\PlatformInstitution;
use App\Models\Student;
use App\Models\User;

class PlatformInstitutionHelper
{
    public static function isMainPlatformAdmin(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower(trim((string) ($user->role ?? '')));

        if (!in_array($role, ['admin', 'staff'], true)) {
            return false;
        }

        return empty($user->platform_institution_id);
    }

    public static function isPartnerCompanyAdmin(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return strtolower(trim((string) ($user->role ?? ''))) === 'partner_company'
            && !empty($user->platform_institution_id);
    }

    public static function hasAdminAccess(?User $user): bool
    {
        return self::isMainPlatformAdmin($user) || self::isPartnerCompanyAdmin($user);
    }

    public static function resolveForUser(?User $user): ?PlatformInstitution
    {
        if (!$user || empty($user->platform_institution_id)) {
            return null;
        }

        return PlatformInstitution::find($user->platform_institution_id);
    }

    public static function resolveForStudent(?Student $student): ?PlatformInstitution
    {
        if (!$student || empty($student->platform_institution_id)) {
            return null;
        }

        return PlatformInstitution::find($student->platform_institution_id);
    }

    public static function institutionPayload(?PlatformInstitution $institution): ?array
    {
        return $institution?->toPublicArray();
    }

    /** Seeded QA partners and *.demo accounts — never blocked at login for payment. */
    public static function isTestingPartnerAccount(?User $user, ?PlatformInstitution $institution): bool
    {
        $suffix = strtolower((string) config('institution.demo_partner_email_suffix', '.demo'));
        $demoSlugs = config('institution.demo_partner_slugs', []);

        $emails = array_filter([
            strtolower(trim((string) ($user?->email ?? ''))),
            strtolower(trim((string) ($institution?->contact_email ?? ''))),
        ]);

        foreach ($emails as $email) {
            if ($suffix !== '' && str_ends_with($email, $suffix)) {
                return true;
            }
        }

        $slug = strtolower(trim((string) ($institution?->slug ?? '')));

        return $slug !== '' && in_array($slug, $demoSlugs, true);
    }

    public static function shouldBlockLoginForPayment(?User $user, ?PlatformInstitution $institution): bool
    {
        if (!config('institution.block_login_for_unpaid_payment', false)) {
            return false;
        }

        if (self::isTestingPartnerAccount($user, $institution)) {
            return false;
        }

        $userStatus = strtolower(trim((string) ($user?->status ?? '')));

        return in_array($userStatus, ['unpaid'], true)
            || strtolower((string) ($institution?->payment_status ?? '')) === 'unpaid';
    }

    public static function canLoginInstitution(?PlatformInstitution $institution): bool
    {
        if (!$institution) {
            return true;
        }

        if ($institution->status === 'disabled') {
            return false;
        }

        if ($institution->status === 'pending_approval') {
            return false;
        }

        return true;
    }

    public static function uniqueSlug(string $name): string
    {
        $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? '', '-'));
        if ($base === '') {
            $base = 'institution';
        }

        $slug = $base;
        $i = 1;
        while (PlatformInstitution::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }
}
