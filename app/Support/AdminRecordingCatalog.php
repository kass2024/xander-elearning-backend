<?php

namespace App\Support;

use App\Models\CourseMaterial;
use App\Models\MeetingRegistration;
use App\Models\WebinarSetting;
use Illuminate\Support\Facades\Schema;

class AdminRecordingCatalog
{
    /**
     * Map Zoom meeting IDs to recording source keys.
     *
     * @return array<string, string>
     */
    public static function sourceByMeetingId(): array
    {
        $map = [];

        $settings = WebinarSetting::current();
        if (!empty($settings->zoom_meeting_id)) {
            $map[(string) $settings->zoom_meeting_id] = 'webinar';
        }

        if (Schema::hasTable('meeting_registrations') && Schema::hasColumn('meeting_registrations', 'zoom_meeting_id')) {
            MeetingRegistration::query()
                ->whereNotNull('zoom_meeting_id')
                ->pluck('zoom_meeting_id')
                ->each(function ($meetingId) use (&$map) {
                    if ($meetingId) {
                        $map[(string) $meetingId] = 'webinar';
                    }
                });
        }

        $legacy = LearnerRecordingAccess::pathwaysMeetingId();
        if ($legacy) {
            $map[(string) $legacy] = 'webinar';
        }

        CourseMaterial::query()
            ->where('type', 'zoom')
            ->get()
            ->each(function (CourseMaterial $material) use (&$map) {
                $meetingId = CourseMaterialHelper::meetingId($material);
                if (!$meetingId || LearnerRecordingAccess::isPathwaysWebinarMeeting($meetingId)) {
                    return;
                }

                $map[(string) $meetingId] = 'live_class';
            });

        return $map;
    }

    /**
     * All Zoom meeting IDs we know about from pathways/webinars and live classes.
     *
     * @return list<string>
     */
    public static function trackedMeetingIds(): array
    {
        return array_values(array_unique(array_keys(self::sourceByMeetingId())));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function filterPlatformOnly(array $items): array
    {
        return array_values(array_filter($items, function (array $item) {
            return in_array($item['source'] ?? '', ['webinar', 'live_class'], true);
        }));
    }

    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            'webinar' => 'Webinar signup',
            'live_class' => 'Live class',
            default => 'Zoom meeting',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function annotateItems(array $items): array
    {
        $sourceMap = self::sourceByMeetingId();

        return array_map(function (array $item) use ($sourceMap) {
            $meetingId = (string) ($item['id'] ?? '');
            $source = $sourceMap[$meetingId] ?? 'other';
            $topic = strtolower((string) ($item['topic'] ?? ''));

            if ($source === 'other' && str_contains($topic, 'pathways webinar')) {
                $source = 'webinar';
            }

            if ($source === 'other' && (str_contains($topic, 'live class') || str_contains($topic, 'zoom session'))) {
                $source = 'live_class';
            }

            $item['source'] = $source;
            $item['source_label'] = self::sourceLabel($source);

            return $item;
        }, $items);
    }
}
