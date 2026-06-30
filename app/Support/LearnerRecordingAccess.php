<?php

namespace App\Support;

use App\Models\CourseEnrollment;
use App\Models\CourseMaterial;
use App\Models\WebinarSetting;
use App\Services\ZoomService;
use App\Support\EnrollmentStatusHelper;

class LearnerRecordingAccess
{
    public static function pathwaysMeetingId(): ?string
    {
        return app(ZoomService::class)->pathwaysMeetingId();
    }

    /**
     * @return array<int, string>
     */
    public static function excludedWebinarMeetingIds(): array
    {
        $ids = [];

        $legacy = self::pathwaysMeetingId();
        if ($legacy) {
            $ids[] = (string) $legacy;
        }

        $settings = WebinarSetting::current();
        if (!empty($settings->zoom_meeting_id)) {
            $ids[] = (string) $settings->zoom_meeting_id;
        }

        return array_values(array_unique($ids));
    }

    public static function isPathwaysWebinarMeeting(?string $meetingId): bool
    {
        if (!$meetingId) {
            return false;
        }

        return in_array((string) $meetingId, self::excludedWebinarMeetingIds(), true);
    }

    public static function hasPaidAccessToCourse(int $studentId, int $courseId): bool
    {
        return CourseEnrollment::query()
            ->where('student_id', $studentId)
            ->where('course_id', $courseId)
            ->whereIn('status', EnrollmentStatusHelper::PAID_STATUSES)
            ->exists();
    }

    public static function hasCourseAccess(int $studentId, int $courseId): bool
    {
        $enrollment = CourseEnrollment::query()
            ->where('student_id', $studentId)
            ->where('course_id', $courseId)
            ->first();

        return $enrollment && EnrollmentStatusHelper::hasCourseAccess($enrollment->status);
    }

    /**
     * Zoom meeting IDs linked to paid/completed course live classes (excludes Pathways webinar room).
     *
     * @return array<int, string>
     */
    public static function liveClassMeetingIdsForStudent(int $studentId, ?int $courseId = null): array
    {
        $courseIds = CourseEnrollment::query()
            ->where('student_id', $studentId)
            ->whereIn('status', EnrollmentStatusHelper::accessStatuses())
            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
            ->pluck('course_id');

        if ($courseIds->isEmpty()) {
            return [];
        }

        return CourseMaterial::query()
            ->whereIn('course_id', $courseIds)
            ->where('type', 'zoom')
            ->get()
            ->map(fn (CourseMaterial $material) => CourseMaterialHelper::meetingId($material))
            ->filter()
            ->reject(fn (?string $meetingId) => self::isPathwaysWebinarMeeting($meetingId))
            ->map(fn (?string $meetingId) => (string) $meetingId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $grouped
     * @param  array<int, string>  $allowedMeetingIds
     * @return array<string, list<array<string, mixed>>>
     */
    public static function filterGroupedRecordings(array $grouped, array $allowedMeetingIds): array
    {
        $allowed = array_fill_keys(array_map('strval', $allowedMeetingIds), true);
        $filtered = [];

        foreach ($grouped as $meetingId => $recordings) {
            if (isset($allowed[(string) $meetingId])) {
                $filtered[(string) $meetingId] = $recordings;
            }
        }

        return $filtered;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $grouped
     * @return list<array<string, mixed>>
     */
    public static function flattenGroupedRecordings(array $grouped): array
    {
        $items = [];

        foreach ($grouped as $recordings) {
            foreach ($recordings as $recording) {
                $items[] = $recording;
            }
        }

        return $items;
    }
}
