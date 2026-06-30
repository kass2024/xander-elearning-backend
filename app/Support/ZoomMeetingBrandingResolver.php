<?php

namespace App\Support;

use App\Models\LiveZoomCohort;
use App\Models\PlatformInstitution;
use App\Models\User;
use App\Services\ZoomService;

class ZoomMeetingBrandingResolver
{
    public function __construct(
        private readonly ZoomService $zoomService,
    ) {
    }

    /**
     * @return array{
     *     host: array{name: string, email: string|null, avatar_url: string|null},
     *     company: array{name: string},
     *     institution?: array<string, mixed>,
     *     use_institution_logo?: bool
     * }
     */
    public function resolve(
        ?string $actorEmail = null,
        ?int $platformInstitutionId = null,
        ?LiveZoomCohort $cohort = null,
    ): array {
        $zoomHost = $this->zoomService->resolveConfiguredHostBranding();
        $institution = $this->resolveInstitution($actorEmail, $platformInstitutionId, $cohort);
        $useInstitutionBranding = $this->shouldUseInstitutionBranding($actorEmail, $institution, $cohort, $platformInstitutionId);

        $companyName = (string) config('app.name', 'Xander Learning Hub');
        $avatarUrl = $zoomHost['avatar_url'];
        $hostName = $zoomHost['name'];

        if ($institution && $useInstitutionBranding) {
            $companyName = $institution->name ?: $companyName;
            $avatarUrl = $this->institutionLogoUrl($institution);
            $hostName = $institution->name ?: $hostName;
        }

        $payload = [
            'host' => [
                'name' => $hostName,
                'email' => $zoomHost['email'],
                'avatar_url' => $avatarUrl,
            ],
            'company' => [
                'name' => $companyName,
            ],
        ];

        if ($institution) {
            $institutionPayload = $institution->toPublicArray();
            $logoUrl = $this->institutionLogoUrl($institution);
            if ($logoUrl) {
                $institutionPayload['logo_url'] = $logoUrl;
            }
            $payload['institution'] = $institutionPayload;
            if ($useInstitutionBranding) {
                $payload['use_institution_logo'] = true;
            }
        }

        return $payload;
    }

    /**
     * Apply Zoom vs institution host avatar/name for SDK join responses.
     *
     * @param  array{name: string, email: string|null, avatar_url: string|null}  $zoomHostContext
     */
    public function finalizeHostSdkBranding(
        array $branding,
        array $zoomHostContext,
        ?User $actorUser,
    ): array {
        $isMainPlatformHost = $actorUser && PlatformInstitutionHelper::isMainPlatformAdmin($actorUser);

        if ($isMainPlatformHost) {
            unset($branding['use_institution_logo']);
            $branding['host']['avatar_url'] = $zoomHostContext['avatar_url'] ?? null;
            $branding['host']['name'] = $zoomHostContext['name'] ?? $branding['host']['name'];
        } elseif ($branding['use_institution_logo'] ?? false) {
            $branding['host']['avatar_url'] = $branding['host']['avatar_url'] ?? null;
        } else {
            $branding['host']['avatar_url'] = $zoomHostContext['avatar_url'] ?? null;
            $branding['host']['name'] = $zoomHostContext['name'] ?? $branding['host']['name'];
        }

        if (empty($branding['host']['email'] ?? null) && !empty($zoomHostContext['email'])) {
            $branding['host']['email'] = $zoomHostContext['email'];
        }

        return $branding;
    }

    private function institutionLogoUrl(PlatformInstitution $institution): ?string
    {
        $raw = !empty($institution->logo_url) ? (string) $institution->logo_url : null;
        if ($raw === null || $raw === '') {
            return null;
        }

        return PublicStorageUrl::toApiAbsoluteUrl($raw) ?? $raw;
    }

    private function shouldUseInstitutionBranding(
        ?string $actorEmail,
        ?PlatformInstitution $institution,
        ?LiveZoomCohort $cohort,
        ?int $platformInstitutionId,
    ): bool {
        if (!$institution) {
            return false;
        }

        if ($actorEmail) {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [strtolower(trim($actorEmail))])
                ->first();

            if ($user && PlatformInstitutionHelper::isMainPlatformAdmin($user)) {
                return false;
            }
        }

        if ($cohort && !empty($cohort->platform_institution_id)) {
            return true;
        }

        if ($platformInstitutionId) {
            return true;
        }

        if ($actorEmail) {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [strtolower(trim($actorEmail))])
                ->first();

            if ($user && !PlatformInstitutionHelper::isMainPlatformAdmin($user)) {
                return !empty($user->platform_institution_id);
            }
        }

        return false;
    }

    private function resolveInstitution(
        ?string $actorEmail,
        ?int $platformInstitutionId,
        ?LiveZoomCohort $cohort,
    ): ?PlatformInstitution {
        if ($cohort && !empty($cohort->platform_institution_id)) {
            return PlatformInstitution::find($cohort->platform_institution_id);
        }

        if ($platformInstitutionId) {
            return PlatformInstitution::find($platformInstitutionId);
        }

        if ($actorEmail) {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [strtolower(trim($actorEmail))])
                ->first();

            return PlatformInstitutionHelper::resolveForUser($user);
        }

        return null;
    }
}
