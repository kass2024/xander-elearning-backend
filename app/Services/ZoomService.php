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
            ],
        ];

        return $client->post("/users/{$userId}/webinars", $payload)->json();
    }
}

