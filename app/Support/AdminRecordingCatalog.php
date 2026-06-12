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
     * @return array<string, array{course_id?: int, course_title?: string, instructor_id?: int, instructor_name?: string, instructor_email?: string}>
     */
    public static function contextByMeetingId(): array
    {
        $map = [];

        CourseMaterial::query()
            ->where('type', 'zoom')
            ->with(['course.instructors'])
            ->get()
            ->each(function (CourseMaterial $material) use (&$map) {
                $meetingId = CourseMaterialHelper::meetingId($material);
                if (!$meetingId || LearnerRecordingAccess::isPathwaysWebinarMeeting($meetingId)) {
                    return;
                }

                $instructors = $material->course?->instructors ?? collect();
                $primary = $instructors->first();

                $map[(string) $meetingId] = [
                    'course_id' => $material->course_id,
                    'course_title' => $material->course?->title,
                    'instructor_id' => $primary?->id,
                    'instructor_name' => $primary?->name,
                    'instructor_email' => $primary?->email,
                ];
            });

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function annotateItems(array $items): array
    {
        $sourceMap = self::sourceByMeetingId();
        $contextMap = self::contextByMeetingId();

        return array_map(function (array $item) use ($sourceMap, $contextMap) {
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

            $context = $contextMap[$meetingId] ?? [];
            if ($context !== []) {
                $item = array_merge($item, $context);
            }

            if ($source === 'webinar' && empty($item['course_title'])) {
                $item['course_title'] = 'Pathways Webinar';
            }

            return $item;
        }, $items);
    }
}
