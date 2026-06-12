<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MailDeliveryService;
use App\Services\ZoomService;
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
        // Use the Zoom user associated with the current access token
        $data = $this->zoom->listRecordings('me');

        if ($data === null) {
            return response()->json(['message' => 'Unable to contact Zoom for recordings'], 500);
        }

        // Bubble up Zoom API errors so the frontend can see what went wrong
        if (is_array($data) && !empty($data['error']) && isset($data['status'])) {
            return response()->json([
                'message' => 'Zoom recordings API error',
                'zoom_status' => $data['status'],
                'zoom_body' => $data['body'] ?? null,
            ], (int) $data['status']);
        }

        $items = [];

        foreach (($data['meetings'] ?? []) as $meeting) {
            $files = [];

            foreach (($meeting['recording_files'] ?? []) as $file) {
                $files[] = [
                    'id'             => $file['id'] ?? null,
                    'recording_type' => $file['recording_type'] ?? null,
                    'file_type'      => $file['file_type'] ?? null,
                    'play_url'       => $file['play_url'] ?? null,
                    'download_url'   => $file['download_url'] ?? null,
                ];
            }

            $items[] = [
                'uuid'       => $meeting['uuid'] ?? null,
                'id'         => $meeting['id'] ?? null,
                'topic'      => $meeting['topic'] ?? 'Recorded meeting',
                'start_time' => $meeting['start_time'] ?? null,
                'duration'   => $meeting['duration'] ?? null,
                'files'      => $files,
            ];
        }

        return response()->json(['recordings' => $items], 200);
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

