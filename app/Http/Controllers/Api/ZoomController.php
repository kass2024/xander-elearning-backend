<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MailDeliveryService;
use App\Services\ZoomService;
use App\Support\AdminRecordingCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ZoomController extends Controller
{
    protected ZoomService $zoom;

    protected MailDeliveryService $mail;

    public function __construct(ZoomService $zoom, MailDeliveryService $mail)
    {
        $this->zoom = $zoom;
        $this->mail = $mail;
    }

    public function listMeetings()
    {
        $data = $this->zoom->listMeetings($this->zoom->hostUserId());

        if ($data === null) {
            return response()->json([
                'meetings' => [],
                'fallback_only' => true,
                'message' => 'Zoom API is not configured or unreachable.',
            ], 200);
        }

        $meetings = [];
        if (is_array($data) && isset($data['meetings']) && is_array($data['meetings'])) {
            $meetings = $data['meetings'];
        }

        return response()->json(array_merge(is_array($data) ? $data : [], ['meetings' => $meetings]), 200);
    }

    public function listRecordings()
    {
        if (!$this->zoom->isConfigured()) {
            return response()->json([
                'message' => 'Zoom API is not configured. Set ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID, and ZOOM_CLIENT_SECRET.',
                'recordings' => [],
                'zoom_api_configured' => false,
            ], 200);
        }

        $trackedIds = AdminRecordingCatalog::trackedMeetingIds();
        $collected = $this->zoom->collectAllCloudRecordings($trackedIds, 12);

        $items = AdminRecordingCatalog::filterPlatformOnly(
            AdminRecordingCatalog::annotateItems(
                $this->zoom->formatRecordingItems(['meetings' => $collected['meetings']])
            )
        );

        $scopeHint = null;
        if ($items === [] && $collected['errors'] !== []) {
            $scopeHint = 'Add Zoom scopes cloud_recording:read:list_user_recordings:admin and cloud_recording:read:list_recording_files:admin to your Server-to-Server app, then re-activate it.';
        }

        return response()->json([
            'recordings' => $items,
            'zoom_api_configured' => true,
            'total' => count($items),
            'tracked_meeting_ids' => count($trackedIds),
            'load_strategies' => $collected['strategies'],
            'zoom_errors' => $collected['errors'],
            'scope_hint' => $scopeHint,
        ], 200);
    }

    public function streamRecording(Request $request)
    {
        $corsHeaders = $this->recordingStreamCorsHeaders($request);

        if ($request->isMethod('OPTIONS')) {
            return response('', 204, $corsHeaders);
        }

        $request->validate([
            'url' => 'required|url|max:4000',
        ]);

        $url = (string) $request->query('url');
        $range = $request->header('Range');
        $headOnly = $request->isMethod('HEAD');

        $probe = $this->zoom->probeRecordingStream($url, $range, $headOnly);

        if ($probe === null || empty($probe['ok'])) {
            return response()->json([
                'message' => $probe['message'] ?? 'Unable to stream this recording',
            ], $probe['status'] ?? 502);
        }

        $forwardHeaders = array_merge($corsHeaders, $probe['headers']);

        if ($headOnly) {
            return response('', $probe['status'], $forwardHeaders);
        }

        /** @var \Illuminate\Http\Client\Response $response */
        $response = $probe['response'];

        return response()->stream(function () use ($response) {
            $body = $response->toPsrResponse()->getBody();
            while (!$body->eof()) {
                echo $body->read(1024 * 128);
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, $probe['status'], $forwardHeaders);
    }

    /**
     * @return array<string, string>
     */
    private function recordingStreamCorsHeaders(Request $request): array
    {
        $allowed = array_values(array_unique(array_filter([
            rtrim((string) config('app.frontend_url'), '/'),
            'https://elearning.xanderglobalscholars.com',
            'http://localhost:8080',
            'http://127.0.0.1:8080',
        ])));

        $origin = (string) $request->headers->get('Origin', '');
        $allowOrigin = in_array($origin, $allowed, true)
            ? $origin
            : ($allowed[0] ?? '*');

        return [
            'Access-Control-Allow-Origin' => $allowOrigin,
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            'Access-Control-Allow-Headers' => 'Range, Accept, Content-Type, Origin',
            'Access-Control-Expose-Headers' => 'Content-Length, Content-Range, Accept-Ranges, Content-Type',
            'Vary' => 'Origin',
        ];
    }

    public function createMeeting(Request $request)
    {
        $request->validate([
            'topic'      => 'required|string|max:255',
            'start_time' => 'nullable|string',
            'duration'   => 'nullable|integer',
            'timezone'   => 'nullable|string',
            'agenda'     => 'nullable|string',
            'invite_emails' => 'nullable|string',
        ]);

        $payload = $request->all();

        // Use logged-in user (instructor) as the Zoom host if available
        $user = $request->user();
        $hostId = $user && !empty($user->email)
            ? (string) $user->email
            : (string) config('services.zoom.host_user_id', 'me');

        $data = $this->zoom->createMeeting($payload, $hostId);
        if ($data === null) {
            return response()->json(['message' => 'Unable to create meeting on Zoom (no response)'], 500);
        }

        if (isset($data['error']) && !empty($data['error'])) {
            $body = $data['body'] ?? [];
            $message = $body['message'] ?? 'Zoom returned an error while creating the meeting.';

            return response()->json([
                'message' => $message,
                'zoom' => $body,
            ], 422);
        }

        // Optionally send invite emails to staff/users
        if (!empty($payload['invite_emails'])) {
            $rawList = $payload['invite_emails'];
            $emails = array_filter(array_map('trim', explode(',', $rawList)));

            if (!empty($emails)) {
                $subject = 'Zoom meeting invitation: ' . ($data['topic'] ?? $payload['topic'] ?? 'Meeting');
                $joinUrl = $data['join_url'] ?? null;
                $password = $data['password'] ?? ($payload['password'] ?? null);
                $startTime = $data['start_time'] ?? ($payload['start_time'] ?? null);

                $lines = [];
                $lines[] = $subject;
                if ($startTime) {
                    $lines[] = 'Time: ' . $startTime;
                }
                if ($joinUrl) {
                    $lines[] = 'Join link: ' . $joinUrl;
                }
                if ($password) {
                    $lines[] = 'Password: ' . $password;
                }
                $lines[] = '';
                $lines[] = 'Sent from ' . config('app.name') . ' system.';

                $bodyText = implode("\n", $lines);

                foreach ($emails as $email) {
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }
                    $this->mail->sendRaw($bodyText, function ($message) use ($email, $subject) {
                        $message->to($email)->subject($subject);
                    }, [
                        'event' => 'zoom_invite',
                        'email' => $email,
                    ]);
                }
            }
        }

        // Include host details and explicit links in the response
        $responseBody = [
            'zoom' => $data,
            'host_name' => $user->name ?? null,
            'host_email' => $user->email ?? null,
            'start_url' => $data['start_url'] ?? null,
            'join_url' => $data['join_url'] ?? null,
        ];

        return response()->json($responseBody, 201);
    }

    public function deleteMeeting(string $id)
    {
        $ok = $this->zoom->deleteMeeting($id);
        if (!$ok) {
            return response()->json(['message' => 'Unable to delete meeting on Zoom'], 500);
        }

        return response()->json(['message' => 'Meeting deleted on Zoom']);
    }

    public function deleteRecording(Request $request, string $meetingId)
    {
        if (!$this->zoom->isConfigured()) {
            return response()->json(['message' => 'Zoom API is not configured'], 503);
        }

        $data = $request->validate([
            'recording_id' => 'nullable|string|max:255',
            'uuid' => 'nullable|string|max:500',
        ]);

        $targetId = !empty($data['uuid']) ? (string) $data['uuid'] : $meetingId;
        $result = $this->zoom->deleteCloudRecording($targetId, $data['recording_id'] ?? null);

        if (empty($result['ok'])) {
            Log::warning('Zoom cloud recording delete failed', [
                'meeting_id' => $meetingId,
                'target_id' => $targetId,
                'recording_id' => $data['recording_id'] ?? null,
                'result' => $result,
            ]);

            return response()->json([
                'message' => $result['message'] ?? 'Unable to delete recording from Zoom cloud',
                'details' => $result['body'] ?? null,
            ], $result['status'] ?? 502);
        }

        return response()->json([
            'message' => $result['message'] ?? 'Recording deleted from Zoom cloud',
        ]);
    }

    public function setMeetingRecording(Request $request, string $id)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        if ($id === 'pathways-webinar') {
            return response()->json([
                'message' => 'Use Webinar Signups to manage recording for registered meetings.',
            ], 422);
        }

        if ($this->zoom->isLegacyPathwaysPmiId($id)) {
            return response()->json([
                'message' => 'This personal meeting room cannot be managed via the Zoom API. Use Webinar Signups → Start Meeting to create an API session.',
            ], 422);
        }

        $enabled = (bool) $data['enabled'];
        $result = $this->zoom->setMeetingAutoRecording($id, $enabled);

        if ($result === null) {
            return response()->json(['message' => 'Unable to contact Zoom'], 503);
        }

        if (!empty($result['error'])) {
            return response()->json([
                'message' => 'Zoom rejected the recording setting change.',
                'details' => $result['body'] ?? null,
            ], 502);
        }

        return response()->json([
            'message' => $enabled ? 'Cloud recording enabled for this meeting.' : 'Cloud recording disabled.',
            'recording_enabled' => $enabled,
            'meeting_id' => $id,
        ]);
    }

    public function listWebinars()
    {
        $hostId = (string) config('services.zoom.host_user_id', 'me');
        $data = $this->zoom->listWebinars($hostId);
        if ($data === null) {
            return response()->json(['message' => 'Unable to contact Zoom'], 500);
        }

        return response()->json($data, 200);
    }

    public function createWebinar(Request $request)
    {
        $request->validate([
            'topic'      => 'required|string|max:255',
            'start_time' => 'nullable|string',
            'duration'   => 'nullable|integer',
            'timezone'   => 'nullable|string',
            'agenda'     => 'nullable|string',
        ]);

        $payload = $request->all();

        // Use logged-in user (instructor) as the Zoom host if available
        $user = $request->user();
        $hostId = $user && !empty($user->email)
            ? (string) $user->email
            : (string) config('services.zoom.host_user_id', 'me');

        $data = $this->zoom->createWebinar($payload, $hostId);
        if ($data === null) {
            return response()->json(['message' => 'Unable to create webinar on Zoom'], 500);
        }

        // Include host details and explicit links in the response
        $responseBody = [
            'zoom' => $data,
            'host_name' => $user->name ?? null,
            'host_email' => $user->email ?? null,
            'start_url' => $data['start_url'] ?? null,
            'join_url' => $data['join_url'] ?? null,
        ];

        return response()->json($responseBody, 201);
    }
}

