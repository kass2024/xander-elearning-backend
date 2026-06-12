<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ZoomService
{
    protected string $accountId;
    protected string $clientId;
    protected string $clientSecret;

    public function __construct()
    {
        $this->accountId = (string) config('services.zoom.account_id');
        $this->clientId = (string) config('services.zoom.client_id');
        $this->clientSecret = (string) config('services.zoom.client_secret');
    }

    protected function getAccessToken(): ?string
    {
        if (!$this->accountId || !$this->clientId || !$this->clientSecret) {
            return null;
        }

        return Cache::remember('zoom_access_token_v2', 3200, function () {
            $response = Http::asForm()
                ->timeout(20)
                ->withHeaders([
                    'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                ])
                ->post('https://zoom.us/oauth/token', [
                    'grant_type' => 'account_credentials',
                    'account_id' => $this->accountId,
                ]);

            if ($response->failed() || !isset($response['access_token'])) {
                return null;
            }

            return $response['access_token'];
        });
    }

    protected function client()
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return null;
        }

        return Http::withToken($token)
            ->timeout(20)
            ->baseUrl('https://api.zoom.us/v2');
    }

    public function listMeetings(string $userId = 'me'): ?array
    {
        $client = $this->client();
        if (!$client) {
            return null;
        }

        return $client->get("/users/{$userId}/meetings")->json();
    }

    public function listRecordings(string $userId = 'me'): ?array
    {
        $client = $this->client();
        if (!$client) {
            return null;
        }

        $response = $client->get("/users/{$userId}/recordings", [
            'page_size' => 100,
        ]);

        if ($response->failed()) {
            return [
                'error' => true,
                'status' => $response->status(),
                'body' => $response->json(),
            ];
        }

        return $response->json();
    }

    public function hostUserId(): string
    {
        return (string) config('services.zoom.host_user_id', 'me');
    }

    public function isConfigured(): bool
    {
        return $this->accountId !== '' && $this->clientId !== '' && $this->clientSecret !== '';
    }

    /**
     * Legacy personal-meeting links cannot be updated via the Meetings API.
     */
    public function isLegacyPathwaysPmiId(?string $meetingId): bool
    {
        if (!$meetingId) {
            return false;
        }

        $legacy = $this->pathwaysMeetingId();
        if (!$legacy) {
            return false;
        }

        return (string) $meetingId === (string) $legacy;
    }

    public function canManageMeetingViaApi(?string $meetingId): bool
    {
        $meetingId = trim((string) $meetingId);
        if ($meetingId === '' || $this->isLegacyPathwaysPmiId($meetingId)) {
            return false;
        }

        $meeting = $this->getMeeting($meetingId);

        return is_array($meeting) && empty($meeting['error']);
    }

    /**
     * Create a Zoom instant meeting (type 1) for live webinar start.
     */
    public function createInstantMeeting(array $data, ?string $userId = null): ?array
    {
        $client = $this->client();
        if (!$client) {
            return null;
        }

        $host = $userId ?: $this->hostUserId();

        $payload = [
            'topic' => $data['topic'] ?? 'Live session',
            'type' => 1,
            'duration' => (int) ($data['duration'] ?? 60),
            'agenda' => $data['agenda'] ?? '',
            'settings' => [
                'join_before_host' => (bool) ($data['join_before_host'] ?? false),
                'waiting_room' => (bool) ($data['waiting_room'] ?? true),
                'mute_upon_entry' => $data['mute_upon_entry'] ?? true,
                'auto_recording' => ($data['auto_recording'] ?? false) ? 'cloud' : 'none',
                'host_video' => $data['host_video'] ?? true,
                'participant_video' => $data['participant_video'] ?? false,
                'audio' => $data['audio'] ?? 'both',
            ],
        ];

        $response = $client->post('/users/' . rawurlencode($host) . '/meetings', $payload);

        if ($response->failed()) {
            return [
                'error' => true,
                'status' => $response->status(),
                'body' => $response->json(),
            ];
        }

        return $response->json();
    }

    public function createMeeting(array $data, string $userId = 'me'): ?array
    {
        $client = $this->client();
        if (!$client) {
            return null;
        }

        $startTime = null;
        if (!empty($data['start_time'])) {
            try {
                $dt = new \DateTime($data['start_time']);
                $startTime = $dt->format('Y-m-d\TH:i:s\Z');
            } catch (\Throwable $e) {
                $startTime = $data['start_time'];
            }
        }

        $payload = [
            'topic'      => $data['topic'] ?? 'Meeting',
            'type'       => 2,
            'start_time' => $startTime,
            'duration'   => $data['duration'] ?? 60,
            'timezone'   => $data['timezone'] ?? 'UTC',
            'agenda'     => $data['agenda'] ?? '',
            // If a password is provided, pass it through to Zoom; otherwise Zoom can auto-generate one
            'password'   => $data['password'] ?? null,
            'settings'   => [
                'join_before_host'              => (bool) ($data['join_before_host'] ?? false),
                'waiting_room'                  => (bool) ($data['waiting_room'] ?? !($data['join_before_host'] ?? false)),
                'mute_upon_entry'               => $data['mute_upon_entry'] ?? true,
                'auto_recording'                => ($data['auto_recording'] ?? false) ? 'cloud' : 'none',
                'host_video'                    => $data['host_video'] ?? true,
                'participant_video'             => $data['participant_video'] ?? false,
                'meeting_authentication'        => $data['meeting_authentication'] ?? false,
                'registrants_email_notification'=> $data['registrants_email_notification'] ?? true,
                'allow_multiple_devices'        => $data['allow_multiple_devices'] ?? false,
                'audio'                         => $data['audio'] ?? 'both',
            ],
        ];

        $response = $client->post("/users/{$userId}/meetings", $payload);

        if ($response->failed()) {
            return [
                'error' => true,
                'status' => $response->status(),
                'body' => $response->json(),
            ];
        }

        return $response->json();
    }

    public function deleteMeeting(string $meetingId): bool
    {
        $client = $this->client();
        if (!$client) {
            return false;
        }

        $response = $client->delete("/meetings/{$meetingId}");
        return !$response->failed();
    }

    /**
     * Zoom meeting IDs currently in progress for the account host user.
     *
     * @return array<int, string>
     */
    public function fetchLiveMeetingIds(string $userId = 'me'): array
    {
        $cacheKey = 'zoom_live_meeting_ids_' . $userId;

        return Cache::remember($cacheKey, 20, function () use ($userId) {
            $client = $this->client();
            if (!$client) {
                return [];
            }

            $response = $client->get("/users/{$userId}/meetings", [
                'type' => 'live',
                'page_size' => 100,
            ]);

            if ($response->failed()) {
                return [];
            }

            $meetings = $response->json('meetings') ?? [];

            return collect($meetings)
                ->pluck('id')
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->values()
                ->all();
        });
    }

    public function isMeetingLive(string $meetingId, string $userId = 'me'): bool
    {
        $meetingId = trim($meetingId);
        if ($meetingId === '') {
            return false;
        }

        return in_array($meetingId, $this->fetchLiveMeetingIds($userId), true);
    }

    public function listWebinars(string $userId = 'me'): ?array
    {
        $client = $this->client();
        if (!$client) {
            return null;
        }

        return $client->get("/users/{$userId}/webinars")->json();
    }

    public function createWebinar(array $data, string $userId = 'me'): ?array
    {
        $client = $this->client();
        if (!$client) {
            return null;
        }

        $payload = [
            'topic'      => $data['topic'] ?? 'Webinar',
            'type'       => 5,
            'start_time' => $data['start_time'] ?? null,
            'duration'   => $data['duration'] ?? 60,
            'timezone'   => $data['timezone'] ?? 'UTC',
            'agenda'     => $data['agenda'] ?? '',
            'settings'   => [
                'host_video'       => $data['host_video'] ?? true,
                'panelists_video'  => $data['panelists_video'] ?? true,
                'practice_session' => $data['practice_session'] ?? true,
                'hd_video'         => $data['hd_video'] ?? true,
                'auto_recording'   => ($data['auto_recording'] ?? false) ? 'cloud' : 'none',
            ],
        ];

        return $client->post("/users/{$userId}/webinars", $payload)->json();
    }

    public function extractMeetingIdFromJoinUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        if (preg_match('#/(?:j|wc)/(\d+)#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function pathwaysMeetingId(): ?string
    {
        $fromEnv = trim((string) config('services.pathways_webinar.zoom_meeting_id', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $joinUrl = (string) config('services.pathways_webinar.zoom_join_url', '');

        return $this->extractMeetingIdFromJoinUrl($joinUrl);
    }

    public function getMeeting(string $meetingId): ?array
    {
        $client = $this->client();
        if (!$client) {
            return null;
        }

        $response = $client->get('/meetings/' . rawurlencode($meetingId));
        if ($response->failed()) {
            return [
                'error' => true,
                'status' => $response->status(),
                'body' => $response->json(),
            ];
        }

        return $response->json();
    }

    public function setMeetingAutoRecording(string $meetingId, bool $enabled): ?array
    {
        if ($this->isLegacyPathwaysPmiId($meetingId)) {
            return [
                'error' => true,
                'status' => 400,
                'body' => [
                    'message' => 'Legacy personal meeting rooms cannot be updated via the Zoom API. Create a meeting through the API first.',
                ],
            ];
        }

        $client = $this->client();
        if (!$client) {
            return null;
        }

        $response = $client->patch('/meetings/' . rawurlencode($meetingId), [
            'settings' => [
                'auto_recording' => $enabled ? 'cloud' : 'none',
            ],
        ]);

        if ($response->failed()) {
            return [
                'error' => true,
                'status' => $response->status(),
                'body' => $response->json(),
            ];
        }

        return $response->json();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function recordingsGroupedByMeetingId(string $userId = 'me'): array
    {
        $data = $this->listRecordings($userId);
        if ($data === null || !empty($data['error'])) {
            return [];
        }

        $grouped = [];

        foreach (($data['meetings'] ?? []) as $meeting) {
            $meetingId = (string) ($meeting['id'] ?? '');
            if ($meetingId === '') {
                continue;
            }

            $files = [];
            foreach (($meeting['recording_files'] ?? []) as $file) {
                $files[] = [
                    'id' => $file['id'] ?? null,
                    'recording_type' => $file['recording_type'] ?? null,
                    'file_type' => $file['file_type'] ?? null,
                    'play_url' => $file['play_url'] ?? null,
                    'download_url' => $file['download_url'] ?? null,
                ];
            }

            if (empty($files)) {
                continue;
            }

            $grouped[$meetingId][] = [
                'uuid' => $meeting['uuid'] ?? null,
                'id' => $meeting['id'] ?? null,
                'topic' => $meeting['topic'] ?? 'Recorded session',
                'start_time' => $meeting['start_time'] ?? null,
                'duration' => $meeting['duration'] ?? null,
                'files' => $files,
            ];
        }

        return $grouped;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function formatRecordingItems(?array $zoomListResponse): array
    {
        if ($zoomListResponse === null || !empty($zoomListResponse['error'])) {
            return [];
        }

        $items = [];

        foreach (($zoomListResponse['meetings'] ?? []) as $meeting) {
            $files = [];
            foreach (($meeting['recording_files'] ?? []) as $file) {
                $files[] = [
                    'id' => $file['id'] ?? null,
                    'recording_type' => $file['recording_type'] ?? null,
                    'file_type' => $file['file_type'] ?? null,
                    'play_url' => $file['play_url'] ?? null,
                    'download_url' => $file['download_url'] ?? null,
                ];
            }

            if (empty($files)) {
                continue;
            }

            $items[] = [
                'uuid' => $meeting['uuid'] ?? null,
                'id' => $meeting['id'] ?? null,
                'topic' => $meeting['topic'] ?? 'Recorded session',
                'start_time' => $meeting['start_time'] ?? null,
                'duration' => $meeting['duration'] ?? null,
                'files' => $files,
            ];
        }

        return $items;
    }
}

