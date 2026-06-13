<?php



namespace App\Support;



use App\Models\CourseMaterial;

use App\Services\ZoomService;

use Carbon\Carbon;



class CourseMaterialHelper

{

    public static function materialKind(CourseMaterial $material): string

    {

        $type = strtolower((string) ($material->type ?? ''));

        $url = strtolower((string) ($material->resource_url ?? ''));



        if ($type === 'zoom') {

            return 'zoom';

        }

        if (in_array($type, ['quiz', 'assessment'], true)) {

            return $type;

        }

        if (in_array($type, ['video', 'document', 'pdf'], true)) {

            return $type === 'pdf' ? 'document' : $type;

        }

        if (in_array($type, ['image', 'audio'], true)) {

            return $type;

        }

        if (str_contains($url, '.mp4') || str_contains($url, 'video') || str_contains($url, 'youtube') || str_contains($url, 'vimeo')) {

            return 'video';

        }

        if (str_contains($url, '.pdf') || $type === 'file') {

            return 'document';

        }



        return $type ?: 'resource';

    }



    public static function learnerJoinUrl(CourseMaterial $material): ?string

    {

        $meta = is_array($material->metadata) ? $material->metadata : [];

        if (!empty($meta['join_url']) && is_string($meta['join_url'])) {

            return $meta['join_url'];

        }



        $url = (string) ($material->resource_url ?? '');

        if ($url === '') {

            return null;

        }



        // Participant join links contain /j/; host start links contain /s/

        if (str_contains($url, '/j/')) {

            return $url;

        }



        return null;

    }



    public static function meetingId(CourseMaterial $material): ?string

    {

        $meta = is_array($material->metadata) ? $material->metadata : [];

        $meetingId = $meta['meeting_id'] ?? null;



        return $meetingId ? (string) $meetingId : null;

    }



    public static function scheduledAt(CourseMaterial $material): ?Carbon

    {

        if ($material->scheduled_at) {

            return Carbon::parse($material->scheduled_at);

        }



        $title = (string) ($material->title ?? '');

        if (preg_match('/(?:Zoom session|Live class)\s*-\s*(.+)$/i', $title, $matches)) {

            try {

                return Carbon::parse(trim($matches[1]));

            } catch (\Throwable) {

                return null;

            }

        }



        return null;

    }



    public static function durationMinutes(CourseMaterial $material): int

    {

        $meta = is_array($material->metadata) ? $material->metadata : [];

        $duration = (int) ($meta['duration'] ?? 60);



        return $duration > 0 ? min($duration, 480) : 60;

    }



    /**

     * @return array{

     *   session_status: 'live'|'upcoming'|'ended'|'unknown',

     *   can_join: bool,

     *   is_past: bool,

     *   is_upcoming: bool,

     *   is_live_now: bool,

     *   duration_minutes: int,

     *   zoom_is_live?: bool

     * }

     */

    public static function liveSessionState(CourseMaterial $material, ?array $liveMeetingIds = null): array

    {

        $scheduled = self::scheduledAt($material);

        $durationMinutes = self::durationMinutes($material);

        $meta = is_array($material->metadata) ? $material->metadata : [];

        $meetingId = self::meetingId($material);



        if (!$scheduled) {

            return [

                'session_status' => 'unknown',

                'can_join' => false,

                'is_past' => false,

                'is_upcoming' => false,

                'is_live_now' => false,

                'duration_minutes' => $durationMinutes,

            ];

        }



        $now = now();

        $scheduledEnd = $scheduled->copy()->addMinutes($durationMinutes);

        $sessionStartedAt = null;



        if (!empty($meta['session_started_at'])) {

            try {

                $sessionStartedAt = Carbon::parse($meta['session_started_at']);

            } catch (\Throwable) {

                $sessionStartedAt = null;

            }

        }



        $zoomLive = false;

        if ($meetingId) {

            if ($liveMeetingIds !== null) {

                $zoomLive = in_array($meetingId, array_map('strval', $liveMeetingIds), true);

            } else {

                $zoomLive = app(ZoomService::class)->isMeetingLive($meetingId);

            }

            if ($zoomLive && $sessionStartedAt === null) {
                self::markSessionStarted($material);
                $sessionStartedAt = now();
            }

        }



        $sessionEnd = $sessionStartedAt

            ? $sessionStartedAt->copy()->addMinutes($durationMinutes)

            : $scheduledEnd;



        if ($meetingId) {

            // Zoom-backed sessions: join when the host has started (live on Zoom or marked started), not only at scheduled time.

            $canJoin = $zoomLive || ($sessionStartedAt !== null && $now->lte($sessionEnd));

            $isPast = !$canJoin && ($now->gt($scheduledEnd) || ($sessionStartedAt !== null && $now->gt($sessionEnd)));

        } else {

            // Fallback for legacy rows without a stored meeting id.

            $canJoin = $now->gte($scheduled) && $now->lte($scheduledEnd);

            $isPast = $now->gt($scheduledEnd);

        }



        $isLiveNow = $canJoin;

        $isUpcoming = !$isPast && !$canJoin;



        return [

            'session_status' => $isPast ? 'ended' : ($isLiveNow ? 'live' : 'upcoming'),

            'can_join' => $isLiveNow,

            'is_past' => $isPast,

            'is_upcoming' => $isUpcoming,

            'is_live_now' => $isLiveNow,

            'duration_minutes' => $durationMinutes,

            'zoom_is_live' => $zoomLive,

        ];

    }



    public static function toLiveClassArray(CourseMaterial $material, ?array $liveMeetingIds = null): array

    {

        $scheduled = self::scheduledAt($material);

        $joinUrl = self::learnerJoinUrl($material);

        $state = self::liveSessionState($material, $liveMeetingIds);



        return array_merge([

            'id' => $material->id,

            'course_id' => $material->course_id,

            'title' => $material->title,

            'course_title' => $material->course?->title,

            'join_url' => $joinUrl,

            'start_time' => $scheduled?->toIso8601String(),

            'description' => $material->description,

            'type' => 'live_class',

        ], $state);

    }



    public static function toLearnerArray(CourseMaterial $material, ?array $liveMeetingIds = null): array

    {

        $kind = self::materialKind($material);

        $scheduled = self::scheduledAt($material);

        $state = $kind === 'zoom' ? self::liveSessionState($material, $liveMeetingIds) : [];

        $meta = is_array($material->metadata) ? $material->metadata : [];

        $fileExtras = [];
        if (\App\Support\MaterialFileHelper::isPCloudMaterial($meta)) {
            $filename = (string) ($meta['filename'] ?? $material->title ?? 'file');
            $fileExtras = [
                'storage' => 'pcloud',
                'pcloud_file_id' => (int) $meta['pcloud_file_id'],
                'file_category' => $meta['category'] ?? \App\Support\MaterialFileHelper::categoryFromFilename($filename),
                'file_size' => isset($meta['size']) ? (int) $meta['size'] : null,
                'filename' => $filename,
            ];
        }



        return array_merge([

            'id' => $material->id,

            'course_id' => $material->course_id,

            'title' => $material->title,

            'description' => $material->description,

            'type' => $material->type,

            'kind' => $kind,

            'resource_url' => $kind === 'zoom' ? null : $material->resource_url,

            'join_url' => $kind === 'zoom' ? self::learnerJoinUrl($material) : null,

            'scheduled_at' => $scheduled?->toIso8601String(),

            'duration_minutes' => $kind === 'zoom' ? self::durationMinutes($material) : null,

            'sort_order' => $material->sort_order,

            'created_at' => $material->created_at?->toIso8601String(),

        ], $state, $fileExtras);

    }



    public static function markSessionStarted(CourseMaterial $material): CourseMaterial

    {

        $meta = is_array($material->metadata) ? $material->metadata : [];



        if (empty($meta['session_started_at'])) {

            $meta['session_started_at'] = now()->toIso8601String();

            $material->metadata = $meta;

            $material->save();

        }



        return $material;

    }

}

