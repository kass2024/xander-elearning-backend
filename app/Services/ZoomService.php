<?php

namespace App\Services;

use App\Models\CourseMaterial;
use App\Models\LiveZoomCohort;
use App\Models\WebinarSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;

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

    public function clearAccessTokenCache(): void
    {
        Cache::forget('zoom_access_token_v2');
    }

    public function invalidateHostUserCache(): void
    {
        Cache::forget($this->hostUserCacheKey());
        Cache::forget('zoom_resolved_host_user_id_v1');
        Cache::forget($this->userProfilePictureCacheKey());
        Cache::forget($this->configuredHostBrandingCacheKey());
    }

    protected function userProfilePictureCacheKey(?string $emailOrId = null): string
    {
        $key = strtolower(trim($emailOrId ?? $this->hostUserId()));

        return 'zoom_user_pic_url_v1_' . md5($key !== '' ? $key : '__default__');
    }

    protected function configuredHostBrandingCacheKey(): string
    {
        return 'zoom_host_brand_profile_v2_' . md5(strtolower(trim($this->hostUserId())));
    }

    /**
     * Prefer Zoom legal name (first + last) over short display_name.
     *
     * @param  array<string, mixed>|null  $profile
     */
    public function resolveZoomProfileFullName(?array $profile): string
    {
        if (!is_array($profile)) {
            return '';
        }

        $first = trim((string) ($profile['first_name'] ?? ''));
        $last = trim((string) ($profile['last_name'] ?? ''));
        $full = trim($first . ' ' . $last);
        if ($full !== '') {
            return $full;
        }

        return trim((string) ($profile['display_name'] ?? ''));
    }

    /**
     * Branding for the Zoom meeting host from ZOOM_HOST_USER_ID (.env) — not the CMS login user.
     *
     * @return array{name: string, email: string|null, avatar_url: string|null}
     */
    public function resolveConfiguredHostBranding(): array
    {
        return Cache::remember($this->configuredHostBrandingCacheKey(), 3600, function () {
            $configured = trim($this->hostUserId());
            $profile = $this->fetchConfiguredZoomHostProfile();

            $name = '';
            $email = null;
            $avatarUrl = null;

            if (is_array($profile)) {
                $name = $this->resolveZoomProfileFullName($profile);
                $email = isset($profile['email']) ? trim((string) $profile['email']) : null;
                if ($email === '') {
                    $email = null;
                }
                $pic = trim((string) ($profile['pic_url'] ?? ''));
                if ($pic !== '' && preg_match('#^https?://#i', $pic)) {
                    $avatarUrl = $pic;
                }
            }

            if ($email === null && str_contains($configured, '@')) {
                $email = $configured;
            }

            return [
                'name' => $name !== '' ? $name : (string) config('app.name', 'Xander Learning Hub'),
                'email' => $email,
                'avatar_url' => $avatarUrl,
            ];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fetchConfiguredZoomHostProfile(): ?array
    {
        $client = $this->client();
        if (!$client) {
            return null;
        }

        $configured = trim($this->hostUserId());
        if ($configured === '' || $configured === 'me') {
            return null;
        }

        $candidates = [$configured];
        if (str_contains($configured, '@')) {
            $candidates[] = strtolower($configured);
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            $response = $client->get('/users/' . rawurlencode($candidate));
            if ($response->successful()) {
                return $response->json();
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUserProfile(?string $userIdOrEmail = null): ?array
    {
        $client = $this->client();
        if (!$client) {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([
            $userIdOrEmail ? trim($userIdOrEmail) : null,
            trim($this->hostUserId()),
        ])));

        foreach ($candidates as $candidate) {
            if ($candidate === '' || $candidate === 'me') {
                continue;
            }

            $response = $client->get('/users/' . rawurlencode($candidate));
            if ($response->successful()) {
                return $response->json();
            }
        }

        $response = $client->get('/users/me');
        if ($response->successful()) {
            return $response->json();
        }

        $resolvedHost = $this->resolveHostUserId();
        if ($resolvedHost !== '' && $resolvedHost !== 'me') {
            $response = $client->get('/users/' . rawurlencode($resolvedHost));
            if ($response->successful()) {
                return $response->json();
            }
        }

        return null;
    }

    public function resolveUserProfilePicture(?string $emailOrId = null): ?string
    {
        if ($emailOrId === null || trim($emailOrId) === '') {
            return $this->resolveConfiguredHostBranding()['avatar_url'];
        }

        $cacheKey = $this->userProfilePictureCacheKey($emailOrId);

        return Cache::remember($cacheKey, 3600, function () use ($emailOrId) {
            $profile = $this->getUserProfile($emailOrId);
            if (!is_array($profile)) {
                return null;
            }

            $picUrl = trim((string) ($profile['pic_url'] ?? ''));
            if ($picUrl === '' || !preg_match('#^https?://#i', $picUrl)) {
                return null;
            }

            return $picUrl;
        });
    }

    protected function hostUserCacheKey(): string
    {
        return 'zoom_resolved_host_user_id_v2_' . md5(trim($this->hostUserId()));
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

    /**
     * @param  array<string, mixed>  $query
     */
    protected function safeGet(mixed $client, string $path, array $query = []): ?Response
    {
        if (!$client) {
            return null;
        }

        try {
            return $client->get($path, $query);
        } catch (\Throwable $e) {
            Log::warning('Zoom API request failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function listMeetings(string $userId = 'me'): ?array
    {
        $client = $this->client();
        if (!$client) {
            return null;
        }

        $host = $userId === 'me' ? $this->hostUserId() : $userId;

        return $client->get('/users/' . rawurlencode($host) . '/meetings')->json();
    }

    public function listRecordings(?string $userId = null, int $monthsBack = 6): ?array
    {
        $client = $this->client();
        if (!$client) {
            return null;
        }

        $host = $userId ?: $this->hostUserId();
        $encodedUser = rawurlencode($host);
        $meetingsByKey = [];

        for ($i = 0; $i < max(1, $monthsBack); $i++) {
            $month = now()->subMonths($i);
            $from = $month->copy()->startOfMonth()->format('Y-m-d');
            $to = $i === 0
                ? now()->format('Y-m-d')
                : $month->copy()->endOfMonth()->format('Y-m-d');

            $nextPageToken = null;

            do {
                $params = [
                    'from' => $from,
                    'to' => $to,
                    'page_size' => 300,
                ];

                if ($nextPageToken) {
                    $params['next_page_token'] = $nextPageToken;
                }

                $response = $this->safeGet($client, "/users/{$encodedUser}/recordings", $params);

                if ($response === null) {
                    return [
                        'error' => true,
                        'status' => 0,
                        'body' => ['message' => 'Zoom API unreachable'],
                    ];
                }

                if ($response->failed()) {
                    return [
                        'error' => true,
                        'status' => $response->status(),
                        'body' => $response->json(),
                    ];
                }

                $page = $response->json();

                foreach (($page['meetings'] ?? []) as $meeting) {
                    $key = (string) ($meeting['uuid'] ?? $meeting['id'] ?? md5(json_encode($meeting)));
                    $meetingsByKey[$key] = $meeting;
                }

                $nextPageToken = $page['next_page_token'] ?? null;
            } while (!empty($nextPageToken));
        }

        $meetings = array_values($meetingsByKey);
        usort($meetings, function (array $a, array $b) {
            return strcmp((string) ($b['start_time'] ?? ''), (string) ($a['start_time'] ?? ''));
        });

        return ['meetings' => $meetings];
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    public function encodeMeetingIdForPath(string $meetingId): string
    {
        $meetingId = trim($meetingId);
        if ($meetingId === '') {
            return '';
        }

        if (str_contains($meetingId, '/') || str_starts_with($meetingId, '+') || str_contains($meetingId, '//')) {
            return rawurlencode(rawurlencode($meetingId));
        }

        return rawurlencode($meetingId);
    }

    /**
     * Cloud recordings for a single meeting (works when list-user-recordings scope is missing).
     */
    public function getMeetingRecordings(string $meetingId): ?array
    {
        $client = $this->client();
        if (!$client) {
            return null;
        }

        $encoded = $this->encodeMeetingIdForPath($meetingId);
        if ($encoded === '') {
            return null;
        }

        $response = $this->safeGet($client, "/meetings/{$encoded}/recordings");

        if ($response === null) {
            return [
                'error' => true,
                'status' => 0,
                'body' => ['message' => 'Zoom API unreachable'],
            ];
        }

        if ($response->status() === 404) {
            return null;
        }

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
     * Account-level cloud recording list (requires account recording scopes).
     */
    public function listAccountRecordings(int $monthsBack = 12): ?array
    {
        $client = $this->client();
        if (!$client || $this->accountId === '') {
            return null;
        }

        $encodedAccount = rawurlencode($this->accountId);
        $meetingsByKey = [];

        for ($i = 0; $i < max(1, $monthsBack); $i++) {
            $month = now()->subMonths($i);
            $from = $month->copy()->startOfMonth()->format('Y-m-d');
            $to = $i === 0
                ? now()->format('Y-m-d')
                : $month->copy()->endOfMonth()->format('Y-m-d');

            $nextPageToken = null;

            do {
                $params = [
                    'from' => $from,
                    'to' => $to,
                    'page_size' => 300,
                ];

                if ($nextPageToken) {
                    $params['next_page_token'] = $nextPageToken;
                }

                $response = $this->safeGet($client, "/accounts/{$encodedAccount}/recordings", $params);

                if ($response === null) {
                    return [
                        'error' => true,
                        'status' => 0,
                        'body' => ['message' => 'Zoom API unreachable'],
                    ];
                }

                if ($response->failed()) {
                    return [
                        'error' => true,
                        'status' => $response->status(),
                        'body' => $response->json(),
                    ];
                }

                $page = $response->json();

                foreach (($page['meetings'] ?? []) as $meeting) {
                    $key = (string) ($meeting['uuid'] ?? $meeting['id'] ?? md5(json_encode($meeting)));
                    $meetingsByKey[$key] = $meeting;
                }

                $nextPageToken = $page['next_page_token'] ?? null;
            } while (!empty($nextPageToken));
        }

        return ['meetings' => array_values($meetingsByKey)];
    }

    /**
     * @return list<string>
     */
    public function hostUserCandidates(): array
    {
        $candidates = array_values(array_unique(array_filter([
            $this->hostUserId(),
            'me',
        ])));

        return $candidates;
    }

    /**
     * Fetch cloud recordings using every available Zoom API strategy.
     *
     * @param  list<string>  $meetingIds
     * @return array{meetings: list<array<string, mixed>>, errors: list<string>, strategies: list<string>}
     */
    public function collectAllCloudRecordings(array $meetingIds = [], int $monthsBack = 6, bool $onlyMissingMeetingIds = true): array
    {
        $meetingsByKey = [];
        $errors = [];
        $strategies = [];

        $merge = function (?array $payload, string $strategy) use (&$meetingsByKey, &$strategies, &$errors): void {
            if ($payload === null) {
                return;
            }

            if (!empty($payload['error'])) {
                $message = $payload['body']['message'] ?? ('HTTP ' . ($payload['status'] ?? '?'));
                $errors[] = $strategy . ': ' . $message;

                return;
            }

            $count = 0;
            foreach (($payload['meetings'] ?? []) as $meeting) {
                if (!is_array($meeting)) {
                    continue;
                }
                $normalized = $this->normalizeRecordingMeeting($meeting);
                if (empty($normalized['recording_files'])) {
                    continue;
                }
                $key = (string) ($normalized['uuid'] ?? $normalized['id'] ?? md5(json_encode($normalized)));
                $meetingsByKey[$key] = $normalized;
                $count++;
            }

            if ($count > 0) {
                $strategies[] = $strategy . ' (' . $count . ')';
            }
        };

        // Prefer account-level list (one sweep) before per-user/per-meeting calls.
        $merge($this->listAccountRecordings($monthsBack), 'account');

        if ($meetingsByKey === []) {
            foreach ($this->hostUserCandidates() as $host) {
                $merge($this->listRecordings($host, $monthsBack), 'user:' . $host);
                if ($meetingsByKey !== []) {
                    break;
                }
            }
        }

        $knownMeetingIds = [];
        foreach ($meetingsByKey as $meeting) {
            $id = (string) ($meeting['id'] ?? '');
            if ($id !== '') {
                $knownMeetingIds[$id] = true;
            }
        }

        foreach (array_unique(array_filter(array_map('strval', $meetingIds))) as $meetingId) {
            if ($onlyMissingMeetingIds && isset($knownMeetingIds[$meetingId])) {
                continue;
            }

            $single = $this->getMeetingRecordings($meetingId);
            if ($single === null) {
                continue;
            }
            if (!empty($single['error'])) {
                $message = $single['body']['message'] ?? ('HTTP ' . ($single['status'] ?? '?'));
                $errors[] = 'meeting:' . $meetingId . ': ' . $message;
                continue;
            }

            $normalized = $this->normalizeRecordingMeeting($single);
            if (empty($normalized['recording_files'])) {
                continue;
            }

            $key = (string) ($normalized['uuid'] ?? $normalized['id'] ?? $meetingId);
            $meetingsByKey[$key] = $normalized;
            $knownMeetingIds[$meetingId] = true;
            $strategies[] = 'meeting:' . $meetingId;
        }

        $meetings = array_values($meetingsByKey);
        usort($meetings, function (array $a, array $b) {
            return strcmp((string) ($b['start_time'] ?? ''), (string) ($a['start_time'] ?? ''));
        });

        return [
            'meetings' => $meetings,
            'errors' => array_values(array_unique($errors)),
            'strategies' => $strategies,
        ];
    }

    /**
     * @param  array<string, mixed>  $meeting
     * @return array<string, mixed>
     */
    public function normalizeRecordingMeeting(array $meeting): array
    {
        $files = [];
        foreach (($meeting['recording_files'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }
            $files[] = [
                'id' => $file['id'] ?? null,
                'recording_type' => $file['recording_type'] ?? null,
                'file_type' => $file['file_type'] ?? null,
                'play_url' => $file['play_url'] ?? null,
                'download_url' => $file['download_url'] ?? null,
                'status' => $file['status'] ?? null,
            ];
        }

        return [
            'uuid' => $meeting['uuid'] ?? null,
            'id' => $meeting['id'] ?? null,
            'topic' => $meeting['topic'] ?? 'Recorded session',
            'start_time' => $meeting['start_time'] ?? null,
            'duration' => $meeting['duration'] ?? null,
            'recording_files' => $files,
        ];
    }

    public function hostUserId(): string
    {
        return (string) config('services.zoom.host_user_id', 'me');
    }

    /**
     * Resolve the Zoom user id used for meeting creation and ZAK requests.
     * Avoids "me" because it breaks ZAK + embedded host start in some SDK builds.
     */
    public function resolveHostUserId(): string
    {
        $configured = trim($this->hostUserId());
        $cacheKey = $this->hostUserCacheKey();

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '' && $this->zoomHostUserExists($cached)) {
            return $cached;
        }

        if (is_string($cached) && $cached !== '') {
            Cache::forget($cacheKey);
        }

        $resolved = $this->resolveHostUserIdFresh($configured);
        Cache::put($cacheKey, $resolved, 3600);

        return $resolved;
    }

    protected function resolveHostUserIdFresh(string $configured): string
    {
        $client = $this->client();
        if (!$client) {
            return $configured !== '' ? $configured : 'me';
        }

        if ($configured !== '' && $configured !== 'me' && !str_contains($configured, '@')) {
            if ($this->zoomHostUserExists($configured)) {
                return $configured;
            }
        }

        if ($configured !== '' && str_contains($configured, '@')) {
            $response = $client->get('/users/' . rawurlencode($configured));
            if ($response->successful()) {
                $id = $response->json('id');
                if (is_string($id) && $id !== '') {
                    return $id;
                }
            }
        }

        $response = $client->get('/users/me');
        if ($response->successful()) {
            $id = $response->json('id');
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return $configured !== '' ? $configured : 'me';
    }

    protected function zoomHostUserExists(string $userId): bool
    {
        if ($userId === '' || $userId === 'me') {
            return true;
        }

        $client = $this->client();
        if (!$client) {
            return false;
        }

        return $client->get('/users/' . rawurlencode($userId))->successful();
    }

    protected function isZoomUserNotFoundMessage(?string $message): bool
    {
        if (!is_string($message) || $message === '') {
            return false;
        }

        return stripos($message, 'user does not exist') !== false
            || stripos($message, 'user not found') !== false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    protected function createMeetingForHost(array $payload, ?string $userId = null): ?array
    {
        $client = $this->client();
        if (!$client) {
            return null;
        }

        $host = $userId ?: $this->resolveHostUserId();
        $response = $client->post('/users/' . rawurlencode($host) . '/meetings', $payload);

        if ($response->failed()) {
            $body = $response->json();
            $message = is_array($body) ? ($body['message'] ?? '') : '';

            if ($this->isZoomUserNotFoundMessage($message)) {
                $this->invalidateHostUserCache();
                $host = $this->resolveHostUserId();
                $response = $client->post('/users/' . rawurlencode($host) . '/meetings', $payload);
            }
        }

        if ($response->failed()) {
            return [
                'error' => true,
                'status' => $response->status(),
                'body' => $response->json(),
            ];
        }

        return $response->json();
    }

    public function isConfigured(): bool
    {
        return $this->accountId !== '' && $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function isAllowedRecordingUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host)) {
            return false;
        }

        return preg_match('/(^|\.)zoom\.(us|com)$/i', $host) === 1;
    }

    public function recordingAccessToken(): ?string
    {
        return $this->getAccessToken();
    }

    /**
     * Fetch a host ZAK token for Meeting SDK (embedded host join).
     *
     * @return array{ok: bool, token?: string, message?: string, scope_hint?: string, status?: int}
     */
    public function fetchHostZakToken(?string $userId = null): array
    {
        $client = $this->client();
        if (!$client) {
            return [
                'ok' => false,
                'message' => 'Zoom OAuth token unavailable. Check ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID, and ZOOM_CLIENT_SECRET.',
            ];
        }

        $candidates = array_values(array_unique(array_filter([
            $userId,
            $this->resolveHostUserId(),
        ])));

        $lastError = null;

        foreach ($candidates as $host) {
            $response = $client->get('/users/' . rawurlencode((string) $host) . '/token', [
                'type' => 'zak',
            ]);

            if ($response->successful()) {
                $token = $response->json('token');
                if (is_string($token) && $token !== '') {
                    return ['ok' => true, 'token' => $token];
                }

                $lastError = [
                    'ok' => false,
                    'message' => 'Zoom returned an empty host token (ZAK).',
                ];
                continue;
            }

            $body = $response->json();
            $lastError = [
                'ok' => false,
                'status' => $response->status(),
                'message' => is_array($body) ? ($body['message'] ?? 'ZAK request failed.') : 'ZAK request failed.',
                'scope_hint' => 'Add user:read:token:admin (or user:read:token) to your Server-to-Server OAuth app, re-activate it, then retry. Set ZOOM_HOST_USER_ID to your Zoom host email if needed.',
            ];
        }

        return $lastError ?? [
            'ok' => false,
            'message' => 'Could not obtain a Zoom host token (ZAK).',
            'scope_hint' => 'Add user:read:token:admin to your Server-to-Server OAuth app and re-activate it.',
        ];
    }

    /**
     * @return array{ok: bool, status?: int, message?: string, headers?: array<string, string>}|null
     */
    public function probeRecordingStream(string $url, ?string $range = null, bool $headOnly = false): ?array
    {
        if (!$this->isAllowedRecordingUrl($url)) {
            return ['ok' => false, 'status' => 403, 'message' => 'Invalid recording URL'];
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return ['ok' => false, 'status' => 503, 'message' => 'Zoom token unavailable'];
        }

        $request = Http::withToken($token)
            ->withHeaders(array_filter(['Range' => $range]))
            ->timeout(120);

        $response = $headOnly ? $request->head($url) : $request->withOptions(['stream' => true])->get($url);

        if ($response->failed()) {
            return [
                'ok' => false,
                'status' => $response->status(),
                'message' => 'Zoom rejected recording stream',
            ];
        }

        $headers = [];
        foreach (['Content-Type', 'Content-Length', 'Content-Range', 'Accept-Ranges'] as $header) {
            $value = $response->header($header);
            if ($value) {
                $headers[$header] = $value;
            }
        }

        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'video/mp4';
        }

        return [
            'ok' => true,
            'status' => $response->status(),
            'headers' => $headers,
            'response' => $response,
        ];
    }

    /**
     * @return \Illuminate\Http\Client\Response|null
     */
    public function fetchRecordingStream(string $url, ?string $range = null)
    {
        if (!$this->isAllowedRecordingUrl($url)) {
            return null;
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return null;
        }

        return Http::withToken($token)
            ->withHeaders(array_filter(['Range' => $range]))
            ->withOptions(['stream' => true])
            ->timeout(300)
            ->get($url);
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @return list<array<string, mixed>>
     */
    public function filterPlayableRecordingFiles(array $files): array
    {
        $allowedFileTypes = ['MP4', 'M4A'];
        $blockedRecordingTypes = ['timeline', 'transcript', 'chat', 'cc', 'caption', 'summary'];

        $filtered = array_values(array_filter($files, function (array $file) use ($allowedFileTypes, $blockedRecordingTypes) {
            $fileType = strtoupper((string) ($file['file_type'] ?? ''));
            if (!in_array($fileType, $allowedFileTypes, true)) {
                return false;
            }

            $recordingType = strtolower((string) ($file['recording_type'] ?? ''));
            foreach ($blockedRecordingTypes as $blocked) {
                if (str_contains($recordingType, $blocked)) {
                    return false;
                }
            }

            return !empty($file['download_url']) || !empty($file['play_url']);
        }));

        usort($filtered, function (array $a, array $b) {
            return self::recordingViewScore($b) <=> self::recordingViewScore($a);
        });

        return array_map(function (array $file) {
            $file['view_label'] = self::recordingViewLabel(
                $file['recording_type'] ?? null,
                $file['file_type'] ?? null
            );

            return $file;
        }, $filtered);
    }

    public static function recordingViewScore(array $file): int
    {
        $fileType = strtoupper((string) ($file['file_type'] ?? ''));
        if ($fileType === 'M4A') {
            return 10;
        }

        if ($fileType !== 'MP4') {
            return 0;
        }

        $type = strtolower((string) ($file['recording_type'] ?? ''));

        if (str_contains($type, 'shared_screen_with_speaker_view')) {
            return 100;
        }
        if ($type === 'shared_screen' || str_contains($type, 'shared_screen_only')) {
            return 95;
        }
        if (str_contains($type, 'shared_screen_with_gallery_view')) {
            return 90;
        }
        if (str_contains($type, 'gallery_view')) {
            return 65;
        }
        if (str_contains($type, 'active_speaker')) {
            return 35;
        }

        return 50;
    }

    public static function recordingViewLabel(?string $recordingType, ?string $fileType): string
    {
        $type = strtolower((string) $recordingType);
        $file = strtoupper((string) $fileType);

        if ($file === 'M4A') {
            return 'Audio only';
        }

        if (str_contains($type, 'shared_screen_with_speaker_view')) {
            return 'Screen + speakers';
        }
        if ($type === 'shared_screen' || str_contains($type, 'shared_screen_only')) {
            return 'Screen share';
        }
        if (str_contains($type, 'shared_screen_with_gallery_view')) {
            return 'Screen + gallery';
        }
        if (str_contains($type, 'gallery_view')) {
            return 'Gallery view';
        }
        if (str_contains($type, 'active_speaker')) {
            return 'Active speaker';
        }

        return $file === 'MP4' ? 'Video' : (string) ($fileType ?: 'Recording');
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
     * Create a persistent cohort meeting (type 3 — recurring, no fixed time).
     * Unlike instant meetings (type 1), the meeting ID stays valid across sessions.
     * Note: type 8 requires recurrence settings; type 3 does not.
     */
    public function createPersistentCohortMeeting(array $data, ?string $userId = null): ?array
    {
        $payload = [
            'topic' => $data['topic'] ?? 'Live session',
            'type' => 3,
            'duration' => max(15, min(240, (int) ($data['duration'] ?? 60))),
            'agenda' => $data['agenda'] ?? '',
            'timezone' => $data['timezone'] ?? 'UTC',
            'settings' => [
                'join_before_host' => (bool) ($data['join_before_host'] ?? true),
                'waiting_room' => (bool) ($data['waiting_room'] ?? false),
                'mute_upon_entry' => $data['mute_upon_entry'] ?? false,
                'auto_recording' => ($data['auto_recording'] ?? false) ? 'cloud' : 'none',
                'host_video' => $data['host_video'] ?? true,
                'participant_video' => $data['participant_video'] ?? true,
                'audio' => $data['audio'] ?? 'both',
                'meeting_authentication' => false,
                'approval_type' => 2,
            ],
        ];

        return $this->createMeetingForHost($payload, $userId);
    }

    /**
     * Create a Zoom instant meeting (type 1) for live webinar start.
     */
    public function createInstantMeeting(array $data, ?string $userId = null): ?array
    {
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

        return $this->createMeetingForHost($payload, $userId);
    }

    public function createMeeting(array $data, string $userId = 'me'): ?array
    {
        $client = $this->client();
        if (!$client) {
            return null;
        }

        $startTime = null;
        if (!empty($data['start_time'])) {
            if ($data['start_time'] instanceof \DateTimeInterface) {
                $startTime = $data['start_time']->format('Y-m-d\TH:i:s');
            } else {
                try {
                    $dt = new \DateTime((string) $data['start_time']);
                    $startTime = $dt->format('Y-m-d\TH:i:s');
                } catch (\Throwable $e) {
                    $startTime = (string) $data['start_time'];
                }
            }
        }

        $host = $userId ?: $this->hostUserId();

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
     * Permanently delete cloud recording(s) for a Zoom meeting.
     *
     * @return array{ok: bool, message?: string, status?: int, body?: mixed}
     */
    public function deleteCloudRecording(string $meetingId, ?string $recordingId = null): array
    {
        $client = $this->client();
        if (!$client) {
            return ['ok' => false, 'message' => 'Zoom API is not configured'];
        }

        $encoded = $this->encodeMeetingIdForPath($meetingId);
        if ($encoded === '') {
            return ['ok' => false, 'message' => 'Invalid meeting id'];
        }

        $path = $recordingId
            ? "/meetings/{$encoded}/recordings/{$recordingId}"
            : "/meetings/{$encoded}/recordings";

        $response = $client->delete($path, ['action' => 'delete']);

        if ($response->successful() || $response->status() === 204) {
            return ['ok' => true, 'message' => 'Recording deleted from Zoom cloud'];
        }

        $body = $response->json();
        $message = is_array($body) ? ($body['message'] ?? 'Zoom rejected the delete request') : 'Zoom rejected the delete request';

        return [
            'ok' => false,
            'message' => $message,
            'status' => $response->status(),
            'body' => $body,
        ];
    }

    /**
     * Zoom meeting IDs currently in progress for the account host user.
     *
     * @return array<int, string>
     */
    public function fetchLiveMeetingIds(string $userId = 'me'): array
    {
        $hostId = $userId === 'me' ? $this->resolveHostUserId() : $userId;
        $cacheKey = 'zoom_live_meeting_ids_' . $hostId;

        try {
            return Cache::remember($cacheKey, 20, function () use ($hostId) {
                $client = $this->client();
                if (!$client) {
                    return [];
                }

                $response = $this->safeGet($client, '/users/' . rawurlencode($hostId) . '/meetings', [
                    'type' => 'live',
                    'page_size' => 100,
                ]);

                if (!$response || $response->failed()) {
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
        } catch (\Throwable $e) {
            Log::warning('Zoom live meetings lookup failed', [
                'host' => $hostId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function isMeetingLive(string $meetingId, string $userId = 'me'): bool
    {
        $meetingId = trim($meetingId);
        if ($meetingId === '') {
            return false;
        }

        $hostId = $userId === 'me' ? $this->resolveHostUserId() : $userId;

        return in_array($meetingId, $this->fetchLiveMeetingIds($hostId), true);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function isMeetingJoinableForEmbed(array $details): bool
    {
        if (!empty($details['error'])) {
            return false;
        }

        $type = (int) ($details['type'] ?? 0);
        // Instant meetings expire once ended — not safe for embedded rejoin.
        if ($type === 1) {
            return false;
        }

        return trim((string) ($details['id'] ?? '')) !== '';
    }

    /**
     * @return array{api_ready: bool, host_user_id: string|null, message: string|null}
     */
    public function configurationStatus(): array
    {
        if (!$this->isConfigured()) {
            return [
                'api_ready' => false,
                'host_user_id' => null,
                'message' => 'Set ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID, and ZOOM_CLIENT_SECRET (Server-to-Server OAuth app).',
            ];
        }

        $readStatus = $this->meetingReadScopeStatus();

        return [
            'api_ready' => true,
            'host_user_id' => $this->resolveHostUserId(),
            'message' => null,
            'meeting_read_ok' => $readStatus['read_ok'],
        ];
    }

    public function assertConfigured(): void
    {
        $status = $this->configurationStatus();
        if (!$status['api_ready']) {
            throw new \RuntimeException($status['message'] ?? 'Zoom API is not configured.');
        }
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

    public function extractPasswordFromJoinUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $params);

        $password = $params['pwd'] ?? $params['password'] ?? null;

        return is_string($password) && $password !== '' ? $password : null;
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    public function isMeetingApiScopeError(?array $details): bool
    {
        if (!is_array($details) || empty($details['error'])) {
            return false;
        }

        $message = json_encode($details['body'] ?? $details, JSON_UNESCAPED_UNICODE);

        return is_string($message) && str_contains($message, 'does not contain scopes');
    }

    /**
     * @return array{read_ok: bool, message: string|null}
     */
    public function meetingReadScopeStatus(): array
    {
        if (!$this->isConfigured()) {
            return ['read_ok' => false, 'message' => 'Zoom API is not configured.'];
        }

        $probeId = '12345678901';
        $details = $this->getMeeting($probeId);

        if ($this->isMeetingApiScopeError($details)) {
            return [
                'read_ok' => false,
                'message' => null,
            ];
        }

        return ['read_ok' => true, 'message' => null];
    }

    /**
     * Resolve passcode for Meeting SDK join (DB column, API, or join URL).
     */
    public function resolveMeetingPassword(LiveZoomCohort $cohort, ?array $meetingDetails = null): string
    {
        $candidates = $this->resolveJoinPasswordCandidates($cohort, $meetingDetails);

        return $candidates[0] ?? '';
    }

    /**
     * Password variants to try with the Meeting SDK (passcode, URL token, empty).
     *
     * @return list<string>
     */
    public function resolveJoinPasswordCandidates(LiveZoomCohort $cohort, ?array $meetingDetails = null): array
    {
        $candidates = [];

        $stored = trim((string) ($cohort->zoom_password ?? ''));
        if ($stored !== '') {
            $candidates[] = $stored;
        }

        if (is_array($meetingDetails)) {
            foreach (['password', 'passcode', 'h323_password'] as $key) {
                $value = $meetingDetails[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $candidates[] = $value;
                }
            }
        }

        $fromUrl = $this->extractPasswordFromJoinUrl($cohort->zoom_link ?? null);
        if ($fromUrl) {
            $candidates[] = $fromUrl;
        }

        $candidates[] = '';

        return array_values(array_unique($candidates, SORT_STRING));
    }

    /**
     * Password variants for course live-class materials (Meeting SDK join).
     *
     * @return list<string>
     */
    public function resolveMaterialJoinPasswordCandidates(CourseMaterial $material, ?array $meetingDetails = null): array
    {
        $candidates = [];
        $meta = is_array($material->metadata) ? $material->metadata : [];

        foreach (['password', 'passcode', 'join_pwd', 'h323_password'] as $key) {
            $value = $meta[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $candidates[] = trim($value);
            }
        }

        if (is_array($meetingDetails) && empty($meetingDetails['error'])) {
            foreach (['password', 'passcode', 'h323_password', 'encrypted_password'] as $key) {
                $value = $meetingDetails[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $candidates[] = trim($value);
                }
            }
        }

        $joinUrl = $meta['join_url'] ?? $material->resource_url ?? null;
        $fromUrl = $this->extractPasswordFromJoinUrl(is_string($joinUrl) ? $joinUrl : null);
        if ($fromUrl) {
            $candidates[] = $fromUrl;
        }

        $candidates[] = '';

        return array_values(array_unique($candidates, SORT_STRING));
    }

    /**
     * Password variants for Meeting Registration webinar (Meeting SDK join).
     *
     * @return list<string>
     */
    public function resolveWebinarJoinPasswordCandidates(WebinarSetting $settings, ?array $meetingDetails = null): array
    {
        $candidates = [];

        $stored = trim((string) ($settings->zoom_password ?? ''));
        if ($stored !== '') {
            $candidates[] = $stored;
        }

        if (is_array($meetingDetails) && empty($meetingDetails['error'])) {
            foreach (['password', 'passcode', 'h323_password', 'encrypted_password'] as $key) {
                $value = $meetingDetails[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $candidates[] = trim($value);
                }
            }
        }

        foreach ([$settings->zoom_join_url ?? null, $settings->zoom_start_url ?? null] as $url) {
            $fromUrl = $this->extractPasswordFromJoinUrl(is_string($url) ? $url : null);
            if ($fromUrl) {
                $candidates[] = $fromUrl;
            }
        }

        $candidates[] = '';

        return array_values(array_unique($candidates, SORT_STRING));
    }

    public function resolveWebinarMeetingPassword(WebinarSetting $settings, ?array $meetingDetails = null): string
    {
        $candidates = $this->resolveWebinarJoinPasswordCandidates($settings, $meetingDetails);

        return $candidates[0] ?? '';
    }

    public function resolveMaterialMeetingPassword(CourseMaterial $material, ?array $meetingDetails = null): string
    {
        $candidates = $this->resolveMaterialJoinPasswordCandidates($material, $meetingDetails);

        return $candidates[0] ?? '';
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

    public function getMeeting(string $meetingId, bool $allowTokenRefresh = true): ?array
    {
        $client = $this->client();
        if (!$client) {
            return null;
        }

        $response = $client->get('/meetings/' . rawurlencode($meetingId));
        if ($response->failed()) {
            $result = [
                'error' => true,
                'status' => $response->status(),
                'body' => $response->json(),
            ];

            if ($allowTokenRefresh && $this->isMeetingApiScopeError($result)) {
                $this->clearAccessTokenCache();

                return $this->getMeeting($meetingId, false);
            }

            return $result;
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
     * @param  array<string, mixed>  $settings
     */
    public function updateMeetingSettings(string $meetingId, array $settings): ?array
    {
        if ($this->isLegacyPathwaysPmiId($meetingId)) {
            return null;
        }

        $client = $this->client();
        if (!$client) {
            return null;
        }

        $response = $client->patch('/meetings/' . rawurlencode($meetingId), [
            'settings' => $settings,
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
     * Start, stop, pause, or resume cloud recording during a live meeting.
     * Uses Zoom Server-to-Server OAuth: PATCH /live_meetings/{meetingId}/events
     *
     * @param  'start'|'stop'|'pause'|'resume'  $action
     */
    public function setLiveRecordingStatus(string $meetingId, string $action): ?array
    {
        $client = $this->client();
        if (!$client) {
            return null;
        }

        $methodMap = [
            'start' => 'recording.start',
            'stop' => 'recording.stop',
            'pause' => 'recording.pause',
            'resume' => 'recording.resume',
        ];

        $method = $methodMap[$action] ?? null;
        if ($method === null) {
            return [
                'error' => true,
                'status' => 422,
                'body' => ['message' => 'Invalid recording action.'],
            ];
        }

        $response = $client->patch('/live_meetings/' . rawurlencode($meetingId) . '/events', [
            'method' => $method,
        ]);

        if ($response->status() === 202 || $response->successful()) {
            return [
                'ok' => true,
                'status' => $response->status(),
                'body' => $response->json() ?: ['message' => 'Recording command accepted by Zoom.'],
            ];
        }

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
     * Cached Zoom cloud recording fetch (shared by admin list + course materials).
     *
     * @param  list<string>  $meetingIds
     * @return array{meetings: list<array<string, mixed>>, errors: list<string>, strategies: list<string>, cached: bool}
     */
    public function cachedCloudRecordings(array $meetingIds = [], int $monthsBack = 6, bool $refresh = false): array
    {
        if ($refresh) {
            $this->bumpRecordingsCacheVersion();
        }

        $ids = array_values(array_unique(array_filter(array_map('strval', $meetingIds))));
        sort($ids);
        $cacheKey = $this->recordingsCacheKey($monthsBack, $ids);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $cached['cached'] = true;

            return $cached;
        }

        try {
            $collected = $this->collectAllCloudRecordings($ids, $monthsBack, true);
        } catch (\Throwable $e) {
            Log::warning('Zoom cloud recordings lookup failed', ['error' => $e->getMessage()]);
            $collected = [
                'meetings' => [],
                'errors' => [$e->getMessage()],
                'strategies' => [],
            ];
        }
        $collected['cached'] = false;
        Cache::put($cacheKey, $collected, now()->addMinutes(5));

        return $collected;
    }

    public function bumpRecordingsCacheVersion(): void
    {
        Cache::put('zoom_recordings_cache_version', $this->recordingsCacheVersion() + 1, now()->addDays(30));
    }

    /**
     * @param  list<string>  $meetingIds
     */
    protected function recordingsCacheKey(int $monthsBack, array $meetingIds): string
    {
        return 'zoom_cloud_recordings_v4_'
            . $this->recordingsCacheVersion() . '_'
            . $monthsBack . '_'
            . md5(json_encode($meetingIds));
    }

    protected function recordingsCacheVersion(): int
    {
        return (int) Cache::get('zoom_recordings_cache_version', 1);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function recordingsGroupedByMeetingId(?string $userId = null): array
    {
        $tracked = array_keys(\App\Support\AdminRecordingCatalog::sourceByMeetingId());
        $data = $this->cachedCloudRecordings($tracked, 6, false);
        if (empty($data['meetings'])) {
            return [];
        }

        $grouped = [];

        foreach ($data['meetings'] as $meeting) {
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
        if ($zoomListResponse === null) {
            return [];
        }

        if (!empty($zoomListResponse['error'])) {
            return [];
        }

        $items = [];

        foreach (($zoomListResponse['meetings'] ?? []) as $meeting) {
            $normalized = $this->normalizeRecordingMeeting(is_array($meeting) ? $meeting : []);
            $files = [];
            foreach (($normalized['recording_files'] ?? []) as $file) {
                $files[] = [
                    'id' => $file['id'] ?? null,
                    'recording_type' => $file['recording_type'] ?? null,
                    'file_type' => $file['file_type'] ?? null,
                    'play_url' => $file['play_url'] ?? null,
                    'download_url' => $file['download_url'] ?? null,
                    'view_label' => $file['view_label'] ?? self::recordingViewLabel(
                        $file['recording_type'] ?? null,
                        $file['file_type'] ?? null
                    ),
                ];
            }

            if (empty($files)) {
                continue;
            }

            $items[] = [
                'uuid' => $normalized['uuid'] ?? null,
                'id' => $normalized['id'] ?? null,
                'topic' => $normalized['topic'] ?? 'Recorded session',
                'start_time' => $normalized['start_time'] ?? null,
                'duration' => $normalized['duration'] ?? null,
                'files' => $this->filterPlayableRecordingFiles($files),
            ];
        }

        return array_values(array_filter($items, fn (array $item) => !empty($item['files'])));
    }
}

