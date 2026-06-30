<?php

namespace App\Services;

use App\Models\AvailableSchedule;
use App\Models\MeetingRegistration;
use App\Models\WebinarSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MeetingRegistrationFulfillmentService
{
    public function __construct(
        protected ZoomService $zoom,
        protected MeetingRegistrationNotificationService $notifications,
    ) {
    }

    public function provisionAfterRegistration(int $registrationId, ?string $frontendScheduleLabel = null): void
    {
        $registration = MeetingRegistration::query()
            ->with('availableSchedule')
            ->find($registrationId);

        if (!$registration) {
            return;
        }

        try {
            $zoomResult = $this->provisionZoomForRegistration($registration);
            $this->notifications->sendStatusEmail(
                $registration->fresh(['availableSchedule']),
                'Approved',
                null,
                $zoomResult['join_url'],
                $frontendScheduleLabel,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to finalize meeting registration in background', [
                'meeting_registration_id' => $registrationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function provisionForApproval(int $registrationId): void
    {
        $registration = MeetingRegistration::query()
            ->with('availableSchedule')
            ->find($registrationId);

        if (!$registration) {
            return;
        }

        try {
            $zoomResult = $this->provisionZoomForRegistration($registration);
            $this->notifications->sendStatusEmail(
                $registration->fresh(['availableSchedule']),
                'Approved',
                null,
                $zoomResult['join_url'],
            );
        } catch (\Throwable $e) {
            Log::error('Failed to provision meeting approval in background', [
                'meeting_registration_id' => $registrationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function resendJoinLink(int $registrationId): void
    {
        $registration = MeetingRegistration::query()
            ->with('availableSchedule')
            ->find($registrationId);

        if (!$registration || !$registration->email) {
            return;
        }

        try {
            $zoomResult = $this->provisionZoomForRegistration($registration, requireZoom: true);
            if (!$zoomResult['ok']) {
                Log::warning('Could not prepare Zoom join link for resend', [
                    'meeting_registration_id' => $registrationId,
                    'message' => $zoomResult['message'] ?? null,
                ]);

                return;
            }

            $this->notifications->sendStatusEmail(
                $registration->fresh(['availableSchedule']),
                'Approved',
                null,
                $zoomResult['join_url'],
            );
        } catch (\Throwable $e) {
            Log::error('Failed to resend meeting join link in background', [
                'meeting_registration_id' => $registrationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{ok: bool, join_url?: string|null, meeting_id?: string|null, message?: string}
     */
    private function provisionZoomForRegistration(MeetingRegistration $registration, bool $requireZoom = false): array
    {
        $schedule = $registration->availableSchedule;
        $startAt = !empty($registration->zoom_start_time)
            ? Carbon::parse($registration->zoom_start_time)
            : ($schedule ? $this->getNextStartFromSchedule($schedule) : $this->getNextWebinarStartTime());

        $settings = WebinarSetting::current();
        $zoom = $this->ensureScheduledWebinarMeeting($settings, $startAt, $schedule);

        $effectiveJoinUrl = $zoom['ok'] ? ($zoom['join_url'] ?? null) : ($settings->zoom_join_url ?: null);
        $effectiveMeetingId = $zoom['ok'] ? ($zoom['meeting_id'] ?? null) : ($settings->zoom_meeting_id ?: null);

        if (!$zoom['ok'] && $requireZoom) {
            return [
                'ok' => false,
                'message' => $zoom['message'] ?? 'Could not prepare Zoom join link.',
            ];
        }

        if (Schema::hasColumn('meeting_registrations', 'zoom_meeting_id')) {
            $registration->zoom_meeting_id = $effectiveMeetingId;
        }
        if (Schema::hasColumn('meeting_registrations', 'zoom_join_url')) {
            $registration->zoom_join_url = $effectiveJoinUrl;
        }
        if (Schema::hasColumn('meeting_registrations', 'zoom_start_time')) {
            $registration->zoom_start_time = $startAt->toDateTimeString();
        }

        $registration->save();

        return [
            'ok' => (bool) ($zoom['ok'] || $effectiveJoinUrl),
            'join_url' => $effectiveJoinUrl,
            'meeting_id' => $effectiveMeetingId,
            'message' => $zoom['message'] ?? null,
        ];
    }

    private function getNextWebinarStartTime(): Carbon
    {
        $tz = (string) config('services.pathways_webinar.timezone', 'Africa/Kigali');
        $now = Carbon::now($tz);

        $nextSat = $now->copy()->next(Carbon::SATURDAY)->setTime(21, 0, 0);
        $nextSun = $now->copy()->next(Carbon::SUNDAY)->setTime(18, 0, 0);

        if ($now->isSaturday()) {
            $candidate = $now->copy()->setTime(21, 0, 0);
            if ($candidate->greaterThan($now)) {
                $nextSat = $candidate;
            }
        }
        if ($now->isSunday()) {
            $candidate = $now->copy()->setTime(18, 0, 0);
            if ($candidate->greaterThan($now)) {
                $nextSun = $candidate;
            }
        }

        return $nextSat->lessThan($nextSun) ? $nextSat : $nextSun;
    }

    private function getNextStartFromSchedule(?AvailableSchedule $schedule): Carbon
    {
        $tz = (string) ($schedule?->timezone ?: config('services.pathways_webinar.timezone', 'Africa/Kigali'));
        $now = Carbon::now($tz);

        $dow = (int) ($schedule?->day_of_week ?? $now->dayOfWeek);

        $rawStart = (string) ($schedule?->start_time ?? '09:00:00');
        $parts = explode(':', $rawStart);
        $hour = (int) ($parts[0] ?? 9);
        $minute = (int) ($parts[1] ?? 0);

        $candidate = $now->copy()->next($dow)->setTime($hour, $minute, 0);
        if ($now->dayOfWeek === $dow) {
            $today = $now->copy()->setTime($hour, $minute, 0);
            if ($today->greaterThan($now)) {
                $candidate = $today;
            }
        }

        return $candidate;
    }

    private function scheduleDurationMinutes(?AvailableSchedule $schedule): int
    {
        if (!$schedule) {
            return 60;
        }

        $configured = (int) ($schedule->meeting_duration_minutes ?? 0);
        if ($configured >= 15) {
            return min(180, $configured);
        }

        return 60;
    }

    private function scheduleTimezone(?AvailableSchedule $schedule): string
    {
        return (string) ($schedule?->timezone ?: config('services.pathways_webinar.timezone', 'Africa/Kigali'));
    }

    private function scheduledSessionKey(Carbon $startAt): string
    {
        return $startAt->copy()->utc()->format('Y-m-d H:i');
    }

    private function syncApprovedRegistrationZoomLinks(?string $joinUrl, ?string $meetingId): void
    {
        if (!$joinUrl || !Schema::hasColumn('meeting_registrations', 'zoom_join_url')) {
            return;
        }

        $update = ['zoom_join_url' => $joinUrl];
        if ($meetingId && Schema::hasColumn('meeting_registrations', 'zoom_meeting_id')) {
            $update['zoom_meeting_id'] = $meetingId;
        }

        MeetingRegistration::query()
            ->whereRaw("LOWER(COALESCE(status, 'pending')) = 'approved'")
            ->update($update);
    }

    /**
     * @return array{ok: bool, message?: string, join_url?: string|null, start_url?: string|null, meeting_id?: string|null, reused?: bool, details?: mixed}
     */
    private function ensureScheduledWebinarMeeting(
        WebinarSetting $settings,
        Carbon $startAt,
        ?AvailableSchedule $schedule,
    ): array {
        if (!$this->zoom->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'Zoom API credentials are missing. Set ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID, and ZOOM_CLIENT_SECRET.',
            ];
        }

        $sessionKey = $this->scheduledSessionKey($startAt);

        if (
            $settings->zoom_meeting_id &&
            $settings->zoom_join_url &&
            $settings->zoom_scheduled_at &&
            $this->scheduledSessionKey(Carbon::parse($settings->zoom_scheduled_at)) === $sessionKey &&
            $this->zoom->canManageMeetingViaApi((string) $settings->zoom_meeting_id)
        ) {
            return [
                'ok' => true,
                'join_url' => $settings->zoom_join_url,
                'start_url' => $settings->zoom_start_url,
                'meeting_id' => (string) $settings->zoom_meeting_id,
                'reused' => true,
            ];
        }

        $tz = $this->scheduleTimezone($schedule);
        $startLocal = $startAt->copy()->setTimezone($tz);
        $topic = 'Pathways Webinar - ' . $startLocal->format('M j, Y g:i A');

        $meeting = $this->zoom->createMeeting([
            'topic' => $topic,
            'start_time' => $startLocal->format('Y-m-d\TH:i:s'),
            'timezone' => $tz,
            'duration' => $this->scheduleDurationMinutes($schedule),
            'agenda' => 'Registered participants webinar session',
            'auto_recording' => (bool) $settings->recording_enabled,
            'join_before_host' => true,
            'waiting_room' => true,
            'mute_upon_entry' => true,
        ], $this->zoom->hostUserId());

        if ($meeting === null) {
            return [
                'ok' => false,
                'message' => 'Unable to contact Zoom to create the scheduled webinar meeting.',
            ];
        }

        if (!empty($meeting['error'])) {
            return [
                'ok' => false,
                'message' => $meeting['body']['message'] ?? 'Zoom rejected meeting creation.',
                'details' => $meeting['body'] ?? null,
            ];
        }

        $meetingId = isset($meeting['id']) ? (string) $meeting['id'] : null;
        $joinUrl = $meeting['join_url'] ?? null;
        $startUrl = $meeting['start_url'] ?? null;

        if (!$meetingId || !$joinUrl) {
            return [
                'ok' => false,
                'message' => 'Zoom created a meeting but did not return join links.',
            ];
        }

        $settings->zoom_meeting_id = $meetingId;
        $settings->zoom_join_url = $joinUrl;
        $settings->zoom_start_url = $startUrl;
        $settings->zoom_scheduled_at = $startAt;
        $settings->save();

        $this->syncApprovedRegistrationZoomLinks($joinUrl, $meetingId);

        return [
            'ok' => true,
            'join_url' => $joinUrl,
            'start_url' => $startUrl,
            'meeting_id' => $meetingId,
            'reused' => false,
        ];
    }
}
