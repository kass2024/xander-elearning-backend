<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveZoomCohort;
use App\Models\LiveZoomCohortQueueEntry;
use App\Models\Student;
use App\Models\User;
use App\Services\LiveZoomCohortQueueService;
use App\Services\LiveZoomCohortZoomService;
use App\Services\ZoomMeetingSdkService;
use App\Services\ZoomService;
use App\Support\LiveZoomCohortHelper;
use App\Support\PlatformInstitutionHelper;
use App\Support\PlatformTenantScope;
use App\Support\ZoomMeetingBrandingResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class LiveZoomCohortController extends Controller
{
    public function __construct(
        protected LiveZoomCohortQueueService $queueService,
        protected LiveZoomCohortZoomService $zoomService,
        protected ZoomMeetingSdkService $meetingSdkService,
        protected ZoomService $zoomApi,
        protected ZoomMeetingBrandingResolver $brandingResolver,
    ) {
    }

    public function index(Request $request)
    {
        $query = LiveZoomCohort::query();
        PlatformTenantScope::applyToQuery($query, $request);

        if (Schema::hasColumn('livezoom_cohort', 'available_on_date')) {
            $query->orderByRaw('available_on_date IS NULL')
                ->orderBy('available_on_date')
                ->orderBy('start_time');
        } else {
            $query->orderBy('day_of_week')->orderBy('start_time');
        }

        return response()->json($query->get(), 200);
    }

    private function syncDayOfWeek(array $data): array
    {
        if (!empty($data['available_on_date'])) {
            try {
                $data['day_of_week'] = Carbon::parse($data['available_on_date'])->dayOfWeek;
            } catch (\Throwable $e) {
                // keep provided day_of_week
            }
        }

        return $data;
    }

    public function bulkUpsert(Request $request)
    {
        $data = $request->validate([
            'dates' => 'required|array|min:1|max:400',
            'dates.*' => 'date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'timezone' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        if (!Schema::hasColumn('livezoom_cohort', 'available_on_date')) {
            return response()->json([
                'message' => 'Run database migrations to enable calendar scheduling for live cohorts.',
            ], 422);
        }

        $timezone = $data['timezone'] ?? 'Africa/Kigali';
        $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;
        $notes = $data['notes'] ?? null;
        $userId = $request->user()?->id;
        $tenantId = PlatformTenantScope::resolvePartnerTenantId($request);

        $created = 0;
        $updated = 0;

        foreach (array_values(array_unique($data['dates'])) as $date) {
            $payload = $this->syncDayOfWeek([
                'available_on_date' => $date,
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'timezone' => $timezone,
                'is_active' => $isActive,
                'notes' => $notes,
            ]);

            if (Schema::hasColumn('livezoom_cohort', 'session_status')) {
                $payload['session_status'] = 'idle';
            }

            PlatformTenantScope::stampInstitutionId($request, $payload);

            $existingQuery = LiveZoomCohort::query()->where('available_on_date', $date);
            if ($tenantId !== null) {
                $existingQuery->where('platform_institution_id', $tenantId);
            }
            $existing = $existingQuery->first();

            if ($existing) {
                $existing->fill($payload);
                if ($userId) {
                    $existing->created_by = $userId;
                }
                $existing->save();
                $updated++;
            } else {
                if ($userId) {
                    $payload['created_by'] = $userId;
                }
                LiveZoomCohort::create($payload);
                $created++;
            }
        }

        return response()->json([
            'message' => 'Live cohort schedule saved for '.count($data['dates']).' date(s)',
            'created' => $created,
            'updated' => $updated,
        ], 200);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'day_of_week' => 'nullable|integer|min:0|max:6',
            'available_on_date' => 'nullable|date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'timezone' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
            'zoom_link' => 'nullable|url|max:2048',
        ]);

        if (empty($data['day_of_week']) && empty($data['available_on_date'])) {
            throw ValidationException::withMessages([
                'available_on_date' => 'Either day_of_week or available_on_date is required.',
            ]);
        }

        $data = $this->syncDayOfWeek($data);

        $data['timezone'] = $data['timezone'] ?? 'Africa/Kigali';
        $data['is_active'] = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;
        if (Schema::hasColumn('livezoom_cohort', 'session_status')) {
            $data['session_status'] = 'idle';
        }

        if ($request->user()) {
            $data['created_by'] = $request->user()->id;
        }

        PlatformTenantScope::stampInstitutionId($request, $data);

        $slot = LiveZoomCohort::create($data);

        return response()->json([
            'message' => 'Live Zoom cohort created',
            'slot' => $slot,
        ], 201);
    }

    public function update(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        $this->authorizeCohort($request, $liveZoomCohort);
        $data = $request->validate([
            'day_of_week' => 'sometimes|nullable|integer|min:0|max:6',
            'available_on_date' => 'sometimes|nullable|date_format:Y-m-d',
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time' => 'sometimes|required|date_format:H:i',
            'timezone' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
            'zoom_link' => 'nullable|url|max:2048',
        ]);

        $data = $this->syncDayOfWeek($data);

        if (array_key_exists('start_time', $data) && array_key_exists('end_time', $data)) {
            if ($data['end_time'] <= $data['start_time']) {
                return response()->json(['message' => 'end_time must be after start_time'], 422);
            }
        }

        $liveZoomCohort->fill($data);
        $liveZoomCohort->save();

        return response()->json([
            'message' => 'Live Zoom cohort updated',
            'slot' => $liveZoomCohort,
        ], 200);
    }

    public function destroy(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        $this->authorizeCohort($request, $liveZoomCohort);
        $liveZoomCohort->delete();

        return response()->json([
            'message' => 'Live Zoom cohort deleted',
        ], 200);
    }

    public function startSession(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        $this->authorizeCohort($request, $liveZoomCohort);
        try {
            $this->assertZoomApiReady();

            $zoom = $this->zoomService->ensureZoomMeeting($liveZoomCohort);
            if (empty($zoom['ok'])) {
                return response()->json(['message' => $zoom['message'] ?? 'Could not create Zoom meeting.'], 422);
            }

            $liveZoomCohort->refresh();

            return response()->json([
                'message' => ($zoom['reused'] ?? false)
                    ? 'Live cohort session started.'
                    : 'Zoom meeting created and session started. Share the join details with learners.',
                'zoom' => $zoom['zoom'] ?? $this->zoomService->formatZoomPayload($liveZoomCohort),
                'session' => $this->queueService->startSession($liveZoomCohort),
                'slot' => $liveZoomCohort->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function zoomDetails(LiveZoomCohort $liveZoomCohort)
    {
        if (trim((string) ($liveZoomCohort->zoom_link ?? '')) === '') {
            return response()->json(['message' => 'No Zoom meeting has been created for this cohort yet. Start the session first.'], 404);
        }

        return response()->json([
            'zoom' => $this->zoomService->formatZoomPayload($liveZoomCohort),
            'slot' => $liveZoomCohort,
        ]);
    }

    public function endSession(LiveZoomCohort $liveZoomCohort)
    {
        try {
            return response()->json([
                'message' => 'Live cohort session ended.',
                'session' => $this->queueService->endSession($liveZoomCohort),
                'slot' => $liveZoomCohort->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function adminQueue(LiveZoomCohort $liveZoomCohort)
    {
        try {
            return response()->json($this->queueService->adminQueue($liveZoomCohort));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function releaseCurrent(LiveZoomCohort $liveZoomCohort)
    {
        try {
            $result = $this->queueService->releaseCurrent($liveZoomCohort);

            return response()->json([
                'message' => $result['admitted']
                    ? 'Previous participant released. Next person admitted.'
                    : 'Previous participant released.',
                ...$result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function admitNextWaiting(LiveZoomCohort $liveZoomCohort)
    {
        try {
            $liveZoomCohort = $this->ensureLiveSessionWithMeeting($liveZoomCohort);
            $result = $this->queueService->admitNextIfAvailable($liveZoomCohort);

            return response()->json([
                ...$result,
                'queue' => $this->queueService->adminQueue($liveZoomCohort->fresh()),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function admitAllWaiting(LiveZoomCohort $liveZoomCohort)
    {
        try {
            $liveZoomCohort = $this->ensureLiveSessionWithMeeting($liveZoomCohort);
            $result = $this->queueService->admitAllWaiting($liveZoomCohort);

            return response()->json([
                ...$result,
                'queue' => $this->queueService->adminQueue($liveZoomCohort->fresh()),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function admitWaitingEntry(LiveZoomCohort $liveZoomCohort, LiveZoomCohortQueueEntry $queueEntry)
    {
        try {
            if ((int) $queueEntry->livezoom_cohort_id !== (int) $liveZoomCohort->id) {
                return response()->json(['message' => 'Queue entry does not belong to this cohort.'], 404);
            }

            $liveZoomCohort = $this->ensureLiveSessionWithMeeting($liveZoomCohort);
            $result = $this->queueService->admitWaitingEntry($liveZoomCohort, (int) $queueEntry->id);

            return response()->json([
                ...$result,
                'queue' => $this->queueService->adminQueue($liveZoomCohort->fresh()),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function joinQueue(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        try {
            $participant = $this->resolveParticipant($request);
            $entry = $this->queueService->joinQueue(
                $liveZoomCohort,
                $participant['student_id'],
                $participant['display_name'],
                $participant['guest_token'],
                $participant['guest_email'],
                $participant['guest_phone'],
            );

            return response()->json([
                'message' => $entry['is_waiting']
                    ? 'You are in the queue.'
                    : 'You can join now.',
                'entry' => $entry,
                'session' => $this->queueService->queueStatus(
                    $liveZoomCohort->fresh(),
                    $participant['student_id'],
                    $participant['guest_token'],
                )['session'] ?? null,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function publicSession(LiveZoomCohort $liveZoomCohort)
    {
        $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        return response()->json([
            'cohort' => [
                'id' => $liveZoomCohort->id,
                'title' => $liveZoomCohort->notes ?: 'Live Zoom Cohort',
                'session_status' => $liveZoomCohort->session_status ?? 'idle',
                'is_live' => ($liveZoomCohort->session_status ?? 'idle') === 'live',
                'day' => $dayNames[(int) $liveZoomCohort->day_of_week] ?? null,
                'start_time' => $liveZoomCohort->start_time,
                'end_time' => $liveZoomCohort->end_time,
                'timezone' => $liveZoomCohort->timezone,
            ],
            'session' => $this->queueService->queueStatus($liveZoomCohort)['session'] ?? null,
            'queue' => $this->queueService->publicQueueSnapshot($liveZoomCohort),
            'public_join_url' => LiveZoomCohortHelper::publicJoinUrl($liveZoomCohort),
            'guest_join_allowed' => true,
            'embedded_meeting_enabled' => $this->meetingSdkService->isConfigured(),
        ]);
    }

    public function publicQueue(LiveZoomCohort $liveZoomCohort)
    {
        try {
            return response()->json($this->queueService->publicQueueSnapshot($liveZoomCohort));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function markHostInMeeting(LiveZoomCohort $liveZoomCohort)
    {
        try {
            if (($liveZoomCohort->session_status ?? 'idle') !== 'live') {
                $liveZoomCohort = $this->ensureLiveSessionWithMeeting($liveZoomCohort);
            }

            $result = $this->queueService->markHostInMeeting($liveZoomCohort);

            return response()->json([
                ...$result,
                'queue' => $this->queueService->adminQueue($liveZoomCohort->fresh()),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function markHostLeft(LiveZoomCohort $liveZoomCohort)
    {
        $this->queueService->clearHostInMeeting($liveZoomCohort);

        return response()->json(['message' => 'Host marked as left.']);
    }

    public function participantSdkAuth(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        try {
            $this->assertZoomProductionReady();

            $participant = $this->resolveParticipant($request, false);
            $status = $this->queueService->queueStatus(
                $liveZoomCohort,
                $participant['student_id'] ?? null,
                $participant['guest_token'] ?? null,
            );
            $entry = $status['my_entry'] ?? null;

            if (!$entry || empty($entry['can_join'])) {
                $message = 'You are not authorized to join the meeting yet. Wait for your turn in the queue.';
                if ($entry && !empty($entry['is_admitted']) && empty($entry['can_join'])) {
                    $message = $this->queueService->isHostInMeeting($liveZoomCohort)
                        ? 'The host is still connecting. Please wait a moment and try again.'
                        : 'You are admitted. Waiting for the host to start the meeting — stay on this page.';
                }

                return response()->json(['message' => $message], 403);
            }

            if (($liveZoomCohort->session_status ?? 'idle') !== 'live') {
                $liveZoomCohort = $this->ensureLiveSessionWithMeeting($liveZoomCohort);
            }

            [$liveZoomCohort, $meetingDetails] = $this->zoomService->resolveCohortForSdkAuth($liveZoomCohort->fresh());

            $displayName = $participant['display_name'] ?: ($entry['display_name'] ?? 'Guest');
            $avatarUrl = null;
            if (!empty($participant['student_id'])) {
                $student = Student::query()->find($participant['student_id']);
                if ($student && !empty($student->avatar)) {
                    $avatarUrl = (string) $student->avatar;
                }
            }

            $password = $this->zoomApi->resolveMeetingPassword($liveZoomCohort, $meetingDetails);
            $passwordCandidates = $this->zoomApi->resolveJoinPasswordCandidates($liveZoomCohort, $meetingDetails);

            $payload = $this->meetingSdkService->buildJoinPayload(
                (string) $liveZoomCohort->zoom_meeting_id,
                $displayName,
                0,
                $password,
            );
            $payload['password_candidates'] = $passwordCandidates;

            $cohortTitle = trim((string) ($liveZoomCohort->notes ?? ''));
            if ($cohortTitle === '') {
                $cohortTitle = 'Live Zoom Cohort #' . $liveZoomCohort->id;
            }

            $branding = $this->brandingResolver->resolve(null, null, $liveZoomCohort);

            return response()->json(array_merge([
                'sdk' => $payload,
                'entry' => $entry,
                'participant' => [
                    'name' => $displayName,
                    'avatar_url' => $avatarUrl,
                ],
                'cohort_title' => $cohortTitle,
            ], $branding));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function hostSdkAuth(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        try {
            $this->assertZoomProductionReady();

            if (($liveZoomCohort->session_status ?? 'idle') !== 'live') {
                $zoom = $this->zoomService->ensureZoomMeeting($liveZoomCohort);
                if (empty($zoom['ok'])) {
                    return response()->json(['message' => $zoom['message'] ?? 'Could not prepare Zoom meeting.'], 422);
                }
                $liveZoomCohort = $liveZoomCohort->fresh();
                $this->queueService->startSession($liveZoomCohort);
                $liveZoomCohort = $liveZoomCohort->fresh();
            }

            if ($request->boolean('force_refresh') || $request->boolean('meeting_stale')) {
                $liveZoomCohort = $this->zoomService->refreshMeetingForSdk($liveZoomCohort);
                $meetingDetails = null;
            } else {
                [$liveZoomCohort, $meetingDetails] = $this->zoomService->resolveCohortForSdkAuth($liveZoomCohort);
            }

            if ($request->boolean('force_refresh') || $request->boolean('refresh_host_profile')) {
                $this->zoomApi->invalidateHostUserCache();
            }

            if (trim((string) ($liveZoomCohort->zoom_meeting_id ?? '')) === '') {
                return response()->json(['message' => 'Start the cohort session first to create a Zoom meeting.'], 422);
            }

            $hostContext = $this->resolveHostContext($request);

            $password = $this->zoomApi->resolveMeetingPassword(
                $liveZoomCohort,
                is_array($meetingDetails) ? $meetingDetails : null,
            );
            $passwordCandidates = $this->zoomApi->resolveJoinPasswordCandidates(
                $liveZoomCohort,
                is_array($meetingDetails) ? $meetingDetails : null,
            );

            $cohortTitle = trim((string) ($liveZoomCohort->notes ?? ''));
            if ($cohortTitle === '') {
                $cohortTitle = 'Live Zoom Cohort #' . $liveZoomCohort->id;
            }

            $actorEmail = PlatformTenantScope::resolveActorEmail($request) ?: $hostContext['email'];

            $branding = $this->brandingResolver->resolve(
                $actorEmail,
                $liveZoomCohort->platform_institution_id ? (int) $liveZoomCohort->platform_institution_id : null,
                $liveZoomCohort,
            );
            $branding['host']['email'] = $hostContext['email'];

            $actorUser = $actorEmail
                ? User::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($actorEmail))])->first()
                : null;
            $branding = $this->brandingResolver->finalizeHostSdkBranding(
                $branding,
                $hostContext,
                $actorUser,
            );

            $hostName = trim((string) ($branding['host']['name'] ?? $hostContext['name']));

            // Embedded Meeting SDK: same-account host uses role=1 JWT signature (no ZAK).
            $payload = $this->meetingSdkService->buildJoinPayload(
                (string) $liveZoomCohort->zoom_meeting_id,
                $hostName,
                1,
                $password,
                null,
                $hostContext['email'],
            );
            $payload['password_candidates'] = $passwordCandidates;

            return response()->json(array_merge([
                'sdk' => $payload,
                'queue' => $this->queueService->adminQueue($liveZoomCohort),
                'meeting_id' => $liveZoomCohort->zoom_meeting_id,
                'meeting_refreshed' => $request->boolean('force_refresh') || $request->boolean('meeting_stale'),
                'zoom' => $this->zoomConfigurationPayload($liveZoomCohort),
                'backend_app' => (string) config('app.name'),
                'cohort_title' => $cohortTitle,
            ], $branding));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function toggleRecording(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        $data = $request->validate([
            'action' => 'required|string|in:start,stop,pause,resume',
        ]);

        $meetingId = trim((string) ($liveZoomCohort->zoom_meeting_id ?? ''));
        if ($meetingId === '') {
            return response()->json(['message' => 'No active Zoom meeting for this cohort.'], 422);
        }

        $result = $this->zoomApi->setLiveRecordingStatus($meetingId, $data['action']);
        if ($result === null) {
            return response()->json(['message' => 'Zoom API is not configured.'], 422);
        }
        if (!empty($result['error'])) {
            $message = data_get($result, 'body.message', 'Zoom rejected the recording request.');
            if (stripos((string) $message, 'not recognized') !== false || (int) ($result['status'] ?? 0) === 404) {
                $message = 'Zoom recording control failed. Ensure Cloud Recording is enabled and your S2S app has meeting:write:admin scope.';
            }

            return response()->json([
                'message' => $message,
                'details' => $result['body'] ?? null,
            ], 422);
        }

        return response()->json([
            'message' => 'Recording ' . $data['action'] . ' request sent.',
            'result' => $result,
        ]);
    }

    public function attendance(LiveZoomCohort $liveZoomCohort)
    {
        try {
            return response()->json($this->queueService->attendanceList($liveZoomCohort));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function queueStatus(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        try {
            $participant = $this->resolveParticipant($request, false);

            return response()->json(
                $this->queueService->queueStatus(
                    $liveZoomCohort,
                    $participant['student_id'] ?? null,
                    $participant['guest_token'] ?? null,
                )
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function leaveQueue(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        try {
            $participant = $this->resolveParticipant($request, false);

            return response()->json(
                $this->queueService->leaveQueue(
                    $liveZoomCohort,
                    $participant['student_id'] ?? null,
                    $participant['guest_token'] ?? null,
                )
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function markJoined(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        try {
            $participant = $this->resolveParticipant($request, false);

            return response()->json([
                'entry' => $this->queueService->markJoined(
                    $liveZoomCohort,
                    $participant['student_id'] ?? null,
                    $participant['guest_token'] ?? null,
                ),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function releaseParticipant(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        try {
            $participant = $this->resolveParticipant($request, false);
            $result = $this->queueService->releaseParticipantTurn(
                $liveZoomCohort,
                $participant['student_id'] ?? null,
                $participant['guest_token'] ?? null,
            );

            return response()->json([
                'message' => 'Thank you. The next person in the queue has been notified.',
                ...$result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @return array{student_id: ?int, guest_token: ?string, display_name: string, guest_email: ?string, guest_phone: ?string}
     */
    protected function resolveParticipant(Request $request, bool $requireIdentity = true): array
    {
        $request->merge([
            'guest_name' => trim((string) $request->input('guest_name', '')) ?: null,
            'guest_email' => trim((string) $request->input('guest_email', '')) ?: null,
            'guest_phone' => trim((string) $request->input('guest_phone', '')) ?: null,
            'guest_token' => trim((string) $request->input('guest_token', '')) ?: null,
            'display_name' => trim((string) $request->input('display_name', '')) ?: null,
        ]);

        $data = $request->validate([
            'student_id' => 'nullable|integer',
            'guest_token' => 'nullable|string|max:64',
            'guest_name' => 'nullable|string|max:120',
            'guest_email' => 'nullable|email|max:190',
            'guest_phone' => 'nullable|string|max:30',
            'display_name' => 'nullable|string|max:120',
        ]);

        if (!empty($data['student_id'])) {
            $student = Student::query()->find($data['student_id']);
            if (!$student) {
                throw ValidationException::withMessages(['student_id' => 'Student not found.']);
            }

            $displayName = trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? ''));
            if ($displayName === '') {
                $displayName = (string) ($student->email ?? 'Learner');
            }

            return [
                'student_id' => (int) $student->id,
                'guest_token' => null,
                'display_name' => $displayName,
                'guest_email' => null,
                'guest_phone' => null,
            ];
        }

        $guestToken = trim((string) ($data['guest_token'] ?? ''));
        $guestName = trim((string) ($data['guest_name'] ?? $data['display_name'] ?? ''));
        $guestEmail = trim((string) ($data['guest_email'] ?? ''));
        $guestPhone = trim((string) ($data['guest_phone'] ?? ''));

        if ($requireIdentity) {
            if ($guestName === '') {
                throw ValidationException::withMessages([
                    'guest_name' => 'Please enter your name to join (no account required).',
                ]);
            }

            if ($guestEmail === '') {
                throw ValidationException::withMessages([
                    'guest_email' => 'Please enter your email to join.',
                ]);
            }

            if ($guestPhone === '') {
                throw ValidationException::withMessages([
                    'guest_phone' => 'Please enter your phone number to join.',
                ]);
            }
        }

        if (!$requireIdentity && $guestToken === '' && $guestName === '') {
            return [
                'student_id' => null,
                'guest_token' => null,
                'display_name' => '',
                'guest_email' => null,
                'guest_phone' => null,
            ];
        }

        return [
            'student_id' => null,
            'guest_token' => $guestToken !== '' ? $guestToken : null,
            'display_name' => $guestName,
            'guest_email' => $guestEmail !== '' ? $guestEmail : null,
            'guest_phone' => $guestPhone !== '' ? $guestPhone : null,
        ];
    }

    /**
     * Zoom host branding for participants (profile picture from Zoom account).
     *
     * @return array{name: string, email: string|null, avatar_url: string|null}
     */
    protected function resolveMeetingHostBranding(): array
    {
        return $this->zoomApi->resolveConfiguredHostBranding();
    }

    /**
     * Resolve host display name and branding for the in-app host studio.
     *
     * @return array{name: string, email: string|null, avatar_url: string|null, company_name: string}
     */
    protected function resolveHostContext(Request $request): array
    {
        $request->validate([
            'host_email' => 'nullable|email|max:255',
        ]);

        $hostEmail = trim((string) $request->input('host_email', ''));
        $user = $hostEmail !== ''
            ? User::query()->where('email', $hostEmail)->first()
            : $request->user();

        $zoomHost = $this->zoomApi->resolveConfiguredHostBranding();

        $zoomName = trim((string) ($zoomHost['name'] ?? ''));
        $requestedName = trim((string) $request->input('host_name', ''));
        // ZOOM_HOST_USER_ID profile always wins — not CMS login or stale localStorage names.
        $name = $zoomName !== '' ? $zoomName : $requestedName;
        if ($name === '' || strcasecmp($name, 'Host') === 0) {
            $name = $user ? (string) ($user->name ?? 'Host') : 'Host';
        }

        $email = $zoomHost['email'] ?? null;
        if ($email === null || $email === '') {
            $email = $hostEmail !== '' ? $hostEmail : $user?->email;
        }

        return [
            'name' => $name,
            'email' => $email,
            'avatar_url' => $zoomHost['avatar_url'],
            'company_name' => (string) config('app.name', 'Xander Learning Hub'),
        ];
    }

    protected function ensureLiveSessionWithMeeting(LiveZoomCohort $cohort): LiveZoomCohort
    {
        if (($cohort->session_status ?? 'idle') === 'live') {
            return $cohort;
        }

        $this->assertZoomApiReady();

        $zoom = $this->zoomService->ensureZoomMeeting($cohort);
        if (empty($zoom['ok'])) {
            throw new \RuntimeException($zoom['message'] ?? 'Could not prepare Zoom meeting.');
        }

        $cohort = $cohort->fresh();
        $this->queueService->startSession($cohort);

        return $cohort->fresh();
    }

    protected function assertZoomApiReady(): void
    {
        $this->zoomApi->assertConfigured();
    }

    protected function assertZoomEmbedReady(): void
    {
        $this->meetingSdkService->assertConfigured();
    }

    protected function assertZoomProductionReady(): void
    {
        $this->assertZoomApiReady();
        $this->assertZoomEmbedReady();
    }

    /**
     * @return array<string, mixed>
     */
    protected function zoomConfigurationPayload(LiveZoomCohort $cohort): array
    {
        $api = $this->zoomApi->configurationStatus();
        $embed = $this->meetingSdkService->configurationStatus();

        return [
            'api_ready' => $api['api_ready'],
            'embed_ready' => $embed['embed_ready'],
            'host_user_id' => $api['host_user_id'] ?? null,
            'embed_client_preview' => $embed['sdk_key_preview'] ?? null,
            'meeting_id' => $cohort->zoom_meeting_id,
            'meeting_number' => preg_replace('/\D+/', '', (string) ($cohort->zoom_meeting_id ?? '')) ?: null,
        ];
    }

    private function authorizeCohort(Request $request, LiveZoomCohort $liveZoomCohort): void
    {
        PlatformTenantScope::assertCanAccess($request, $liveZoomCohort);
    }
}
