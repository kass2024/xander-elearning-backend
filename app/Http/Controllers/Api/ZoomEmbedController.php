<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\CourseMaterial;
use App\Models\Student;
use App\Models\User;
use App\Models\WebinarSetting;
use App\Services\ZoomMeetingSdkService;
use App\Services\ZoomService;
use App\Support\CourseMaterialHelper;
use App\Support\FrontendUrl;
use App\Support\PlatformInstitutionHelper;
use App\Support\ZoomMeetingBrandingResolver;
use App\Models\PlatformInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ZoomEmbedController extends Controller
{
    public function __construct(
        protected ZoomMeetingSdkService $sdkService,
        protected ZoomService $zoomService,
        protected ZoomMeetingBrandingResolver $brandingResolver,
    ) {
    }

    public function config(): JsonResponse
    {
        $embed = $this->sdkService->configurationStatus();
        $api = $this->zoomService->configurationStatus();

        return response()->json([
            'embed_enabled' => $embed['embed_ready'],
            'sdk_key' => $embed['embed_ready'] ? config('services.zoom.sdk_key') : null,
            'sdk_key_preview' => $embed['sdk_key_preview'] ?? null,
            'api_ready' => $api['api_ready'],
            'host_user_id' => $api['host_user_id'] ?? null,
            'frontend_base' => FrontendUrl::base(),
            'platforms' => ['web', 'android'],
        ]);
    }

    public function auth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'material_id' => 'nullable|integer|exists:course_materials,id',
            'meeting_number' => 'nullable|string|max:32',
            'user_name' => 'nullable|string|max:120',
            'role' => 'nullable|integer|in:0,1',
            'password' => 'nullable|string|max:64',
            'instructor_email' => 'nullable|email',
            'user_email' => 'nullable|email',
            'platform_institution_id' => 'nullable|integer',
            'student_id' => 'nullable|integer|exists:students,id',
            'webinar_host' => 'nullable|boolean',
        ]);

        $role = (int) ($data['role'] ?? 0);

        if (!empty($data['material_id'])) {
            return $this->materialAuth(
                CourseMaterial::query()->findOrFail((int) $data['material_id']),
                $role,
                $data
            );
        }

        if (!empty($data['webinar_host'])) {
            return $this->buildWebinarHostAuth($data);
        }

        $meetingNumber = preg_replace('/\D+/', '', (string) ($data['meeting_number'] ?? ''));
        if ($meetingNumber === '') {
            return response()->json(['message' => 'Provide material_id, meeting_number, or webinar_host.'], 422);
        }

        $userName = trim((string) ($data['user_name'] ?? ''));
        if ($userName === '') {
            $userName = $role === 1 ? 'Host' : 'Guest';
        }
        if ($role === 1) {
            $userName = $this->resolveZoomHostJoinName($userName);
        }

        $joinPasswords = $this->resolveSdkJoinPasswords($meetingNumber, $data['password'] ?? null);

        try {
            $payload = $this->sdkService->buildJoinPayload(
                $meetingNumber,
                $userName,
                $role,
                $joinPasswords['password'],
                $this->hostZakForRole($role),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payload['password_candidates'] = $joinPasswords['candidates'];

        $platformInstitutionId = isset($data['platform_institution_id'])
            ? (int) $data['platform_institution_id']
            : null;
        $actorEmail = trim((string) ($data['user_email'] ?? $data['instructor_email'] ?? ''));
        $actorEmail = $actorEmail !== '' ? $actorEmail : null;
        $zoomHost = $this->zoomService->resolveConfiguredHostBranding();
        $branding = $this->meetingBrandingPayload($actorEmail, $platformInstitutionId);
        $actorUser = $actorEmail
            ? User::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($actorEmail))])->first()
            : null;
        $branding = $this->brandingResolver->finalizeHostSdkBranding(
            $branding,
            $zoomHost,
            $actorUser,
        );

        if ($role === 1) {
            $response = ['sdk' => $payload];
            $response = array_merge($response, $branding);
            return response()->json($response);
        }

        return response()->json(['sdk' => $payload]);
    }

    public function learnerMaterialAuth(Request $request, CourseMaterial $material): JsonResponse
    {
        $data = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
        ]);

        return $this->materialAuth($material, 0, $data);
    }

    public function instructorMaterialAuth(Request $request, CourseMaterial $material): JsonResponse
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
        ]);

        return $this->materialAuth($material, 1, $data);
    }

    public function instructorPreviewMaterialAuth(Request $request, CourseMaterial $material): JsonResponse
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
        ]);

        return $this->materialAuth($material, 0, array_merge($data, ['preview' => true]));
    }

    public function webinarHostAuth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_name' => 'nullable|string|max:120',
            'user_email' => 'nullable|email',
            'platform_institution_id' => 'nullable|integer',
            'refresh_host_profile' => 'nullable|boolean',
        ]);

        if ($request->boolean('refresh_host_profile')) {
            $this->zoomService->invalidateHostUserCache();
        }

        return $this->buildWebinarHostAuth($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function materialAuth(CourseMaterial $material, int $role, array $data): JsonResponse
    {
        $material->loadMissing('course');

        if (strtolower((string) $material->type) !== 'zoom') {
            return response()->json(['message' => 'This material is not a live class.'], 422);
        }

        $meetingId = CourseMaterialHelper::meetingId($material);
        if (!$meetingId) {
            return response()->json(['message' => 'No Zoom meeting ID for this session.'], 422);
        }

        if ($role === 1) {
            $email = trim((string) ($data['instructor_email'] ?? ''));
            if ($email === '') {
                return response()->json(['message' => 'Instructor email is required to host.'], 422);
            }

            $instructor = User::query()->where('email', $email)->where('role', 'instructor')->first();
            if (!$instructor || !$instructor->assignedCourses()->where('courses.id', $material->course_id)->exists()) {
                return response()->json(['message' => 'You are not authorized to host this session.'], 403);
            }

            $zoomHost = $this->zoomService->resolveConfiguredHostBranding();
            $userName = trim((string) ($zoomHost['name'] ?? ''));
            if ($userName === '') {
                $userName = trim((string) ($instructor->name ?? '')) ?: 'Instructor';
            }
            $participantAvatar = !empty($instructor->avatar) ? (string) $instructor->avatar : null;
        } else {
            $preview = !empty($data['preview']);
            $email = trim((string) ($data['instructor_email'] ?? ''));
            $participantAvatar = null;

            if ($preview && $email !== '') {
                $instructor = User::query()->where('email', $email)->where('role', 'instructor')->first();
                if (!$instructor || !$instructor->assignedCourses()->where('courses.id', $material->course_id)->exists()) {
                    return response()->json(['message' => 'You are not authorized to preview this session.'], 403);
                }

                $userName = trim((string) ($instructor->name ?? '')) ?: 'Instructor preview';
                $participantAvatar = !empty($instructor->avatar) ? (string) $instructor->avatar : null;
            } else {
                $studentId = (int) ($data['student_id'] ?? 0);
                if ($studentId <= 0) {
                    return response()->json(['message' => 'Student ID is required to join.'], 422);
                }

                $enrolled = CourseEnrollment::query()
                    ->where('course_id', $material->course_id)
                    ->where('student_id', $studentId)
                    ->whereIn('status', ['paid', 'completed'])
                    ->exists();

                if (!$enrolled) {
                    return response()->json(['message' => 'You are not enrolled in this course.'], 403);
                }

                $state = CourseMaterialHelper::liveSessionState($material);
                if (empty($state['can_join'])) {
                    return response()->json(['message' => 'This class is not live yet. Wait for the instructor to start.'], 403);
                }

                $student = Student::query()->find($studentId);
                if (!$student) {
                    return response()->json(['message' => 'Student not found.'], 404);
                }

                $userName = trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? ''));
                if ($userName === '') {
                    $userName = (string) ($student->email ?? 'Learner');
                }
                $participantAvatar = !empty($student->avatar) ? (string) $student->avatar : null;
            }
        }

        $meetingDetails = null;
        $fetched = $this->zoomService->getMeeting($meetingId);
        if (is_array($fetched) && empty($fetched['error'])) {
            $meetingDetails = $fetched;
        }

        $passwordCandidates = $this->zoomService->resolveMaterialJoinPasswordCandidates($material, $meetingDetails);
        $password = $passwordCandidates[0] ?? (CourseMaterialHelper::meetingPassword($material) ?? '');

        $platformInstitutionId = isset($data['platform_institution_id'])
            ? (int) $data['platform_institution_id']
            : null;
        if (!$platformInstitutionId && !empty($material->course?->platform_institution_id)) {
            $platformInstitutionId = (int) $material->course->platform_institution_id;
        }

        $actorEmail = trim((string) ($data['user_email'] ?? $data['instructor_email'] ?? ''));
        $actorEmail = $actorEmail !== '' ? $actorEmail : null;
        $zoomHost = $this->zoomService->resolveConfiguredHostBranding();
        $branding = $this->meetingBrandingPayload($actorEmail, $platformInstitutionId);
        $actorUser = $actorEmail
            ? User::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($actorEmail))])->first()
            : null;
        $branding = $this->brandingResolver->finalizeHostSdkBranding(
            $branding,
            $zoomHost,
            $actorUser,
        );

        if ($role === 1 && ($branding['use_institution_logo'] ?? false)) {
            $userName = trim((string) ($branding['host']['name'] ?? $userName));
        }

        try {
            $payload = $this->sdkService->buildJoinPayload(
                $meetingId,
                $userName,
                $role,
                $password,
                $this->hostZakForRole($role),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payload['password_candidates'] = $passwordCandidates;

        $sessionTitle = trim((string) ($material->title ?? ''));
        if ($sessionTitle !== '') {
            $branding['session_title'] = $sessionTitle;
        }

        return response()->json(array_merge([
            'sdk' => $payload,
            'material' => [
                'id' => $material->id,
                'title' => $material->title,
                'course_title' => $material->course?->title,
            ],
            'preview' => !empty($data['preview']),
            'participant' => [
                'name' => $userName,
                'avatar_url' => $participantAvatar ?? null,
            ],
        ], $branding));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function buildWebinarHostAuth(array $data): JsonResponse
    {
        $settings = WebinarSetting::current();
        $meetingId = trim((string) ($settings->zoom_meeting_id ?? ''));
        if ($meetingId === '') {
            return response()->json(['message' => 'No webinar meeting configured. Prepare the webinar first.'], 422);
        }

        $zoomHost = $this->zoomService->resolveConfiguredHostBranding();
        $userName = $this->resolveZoomHostJoinName($data['user_name'] ?? null);

        $platformInstitutionId = isset($data['platform_institution_id'])
            ? (int) $data['platform_institution_id']
            : null;
        $actorEmail = trim((string) ($data['user_email'] ?? $data['instructor_email'] ?? ''));
        $actorEmail = $actorEmail !== '' ? $actorEmail : null;
        $branding = $this->meetingBrandingPayload($actorEmail, $platformInstitutionId);
        $actorUser = $actorEmail
            ? User::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($actorEmail))])->first()
            : null;
        $branding = $this->brandingResolver->finalizeHostSdkBranding(
            $branding,
            $zoomHost,
            $actorUser,
        );

        if ($branding['use_institution_logo'] ?? false) {
            $userName = trim((string) ($branding['host']['name'] ?? $userName));
        }

        $meetingDetails = $this->fetchMeetingDetailsForSdk($meetingId);
        $joinPasswords = $this->resolveSdkJoinPasswords($meetingId, null, $settings, $meetingDetails);
        $this->persistWebinarPasswordIfResolved($settings, $joinPasswords);

        try {
            // Same-account embedded host: role=1 JWT (no ZAK), matching Live Zoom Cohort.
            $payload = $this->sdkService->buildJoinPayload(
                $meetingId,
                $userName,
                1,
                $joinPasswords['password'],
                null,
                $zoomHost['email'] ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payload['password_candidates'] = $joinPasswords['candidates'];

        return response()->json(array_merge(
            ['sdk' => $payload],
            $branding,
        ));
    }

    /**
     * Zoom host profile picture + display name from ZOOM_HOST_USER_ID (.env).
     *
     * @return array{host: array{name: string, email: string|null, avatar_url: string|null}, company: array{name: string}}
     */
    protected function meetingBrandingPayload(?string $actorEmail = null, ?int $platformInstitutionId = null): array
    {
        return $this->brandingResolver->resolve($actorEmail, $platformInstitutionId);
    }

    protected function hostZakForRole(int $role): ?string
    {
        if ($role !== 1) {
            return null;
        }

        $result = $this->zoomService->fetchHostZakToken();
        if (empty($result['ok'])) {
            return null;
        }

        $token = $result['token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * @return array{password: string, candidates: list<string>}
     */
    protected function resolveSdkJoinPasswords(
        string $meetingId,
        ?string $requestPassword = null,
        ?WebinarSetting $webinarSettings = null,
        ?array $meetingDetails = null,
    ): array {
        $meetingDetails = $meetingDetails ?? $this->fetchMeetingDetailsForSdk($meetingId);

        $settings = $webinarSettings ?? WebinarSetting::current();
        if ((string) ($settings->zoom_meeting_id ?? '') === $meetingId) {
            $candidates = $this->zoomService->resolveWebinarJoinPasswordCandidates($settings, $meetingDetails);
        } else {
            $candidates = [];
            $requestPassword = trim((string) ($requestPassword ?? ''));
            if ($requestPassword !== '') {
                $candidates[] = $requestPassword;
            }
            if (is_array($meetingDetails) && empty($meetingDetails['error'])) {
                foreach (['password', 'passcode', 'h323_password', 'encrypted_password'] as $key) {
                    $value = $meetingDetails[$key] ?? null;
                    if (is_string($value) && trim($value) !== '') {
                        $candidates[] = trim($value);
                    }
                }
            }
            $candidates[] = '';
            $candidates = array_values(array_unique($candidates, SORT_STRING));
        }

        return [
            'password' => $candidates[0] ?? '',
            'candidates' => $candidates,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fetchMeetingDetailsForSdk(string $meetingId): ?array
    {
        if (!$this->zoomService->canManageMeetingViaApi($meetingId)) {
            return null;
        }

        $fetched = $this->zoomService->getMeeting($meetingId);

        return is_array($fetched) && empty($fetched['error']) ? $fetched : null;
    }

    protected function persistWebinarPasswordIfResolved(WebinarSetting $settings, array $joinPasswords): void
    {
        if (!Schema::hasColumn('webinar_settings', 'zoom_password')) {
            return;
        }

        if (trim((string) ($settings->zoom_password ?? '')) !== '') {
            return;
        }

        foreach ($joinPasswords['candidates'] as $candidate) {
            if ($candidate !== '') {
                $settings->zoom_password = $candidate;
                $settings->save();
                break;
            }
        }
    }

    protected function resolveZoomHostJoinName(?string $fallback = null): string
    {
        $zoomName = trim((string) ($this->zoomService->resolveConfiguredHostBranding()['name'] ?? ''));
        if ($zoomName !== '') {
            return $zoomName;
        }

        $fallback = trim((string) ($fallback ?? ''));
        if ($fallback !== '' && strcasecmp($fallback, 'Host') !== 0) {
            return $fallback;
        }

        return 'Host';
    }
}
