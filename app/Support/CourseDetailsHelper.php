<?php

namespace App\Support;

use App\Models\Course;
use App\Services\CourseCodeGenerator;
use Illuminate\Http\Request;

class CourseDetailsHelper
{
    public static function validationRules(?int $ignoreCourseId = null): array
    {
        $uniqueRule = 'nullable|string|max:32|unique:courses,course_code';
        if ($ignoreCourseId) {
            $uniqueRule .= ',' . $ignoreCourseId;
        }

        return [
            'course_code' => $uniqueRule,
            'auto_generate_code' => 'nullable|boolean',
            'code_prefix' => 'nullable|string|max:6',
            'general_information' => 'nullable|string',
            'important_information' => 'nullable|string',
            'guidelines' => 'nullable',
            'how_to_use' => 'nullable',
            'attendance_policy' => 'nullable|string',
            'assessment_policy' => 'nullable|string',
        ];
    }

    public static function extractFromRequest(Request $request): array
    {
        $guidelines = self::parseStringArray($request->input('guidelines'));
        $howToUse = self::parseHowToUse($request->input('how_to_use'));

        return [
            'course_code' => self::normalizeCode($request->input('course_code')),
            'auto_generate_code' => $request->boolean('auto_generate_code'),
            'code_prefix' => self::normalizeCode($request->input('code_prefix')),
            'general_information' => $request->input('general_information'),
            'important_information' => $request->input('important_information'),
            'guidelines' => $guidelines,
            'how_to_use' => $howToUse,
            'attendance_policy' => $request->input('attendance_policy'),
            'assessment_policy' => $request->input('assessment_policy'),
        ];
    }

    public static function applyToPayload(array &$payload, array $details, ?string $title = null): void
    {
        $code = $details['course_code'] ?? null;
        if (!$code && !empty($details['auto_generate_code'])) {
            $code = CourseCodeGenerator::generate($details['code_prefix'] ?? null, $title);
        }

        if ($code) {
            $payload['course_code'] = $code;
        }

        foreach ([
            'general_information',
            'important_information',
            'attendance_policy',
            'assessment_policy',
        ] as $field) {
            if (array_key_exists($field, $details)) {
                $payload[$field] = $details[$field];
            }
        }

        if (array_key_exists('guidelines', $details)) {
            $payload['guidelines'] = $details['guidelines'] ?? [];
        }

        if (array_key_exists('how_to_use', $details)) {
            $payload['how_to_use'] = $details['how_to_use'] ?? [];
        }
    }

    public static function toArray(Course $course): array
    {
        return [
            'course_code' => $course->course_code,
            'general_information' => $course->general_information,
            'important_information' => $course->important_information,
            'guidelines' => $course->guidelines ?? [],
            'how_to_use' => $course->how_to_use ?? [],
            'attendance_policy' => $course->attendance_policy,
            'assessment_policy' => $course->assessment_policy,
        ];
    }

    private static function normalizeCode(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $value));
    }

    private static function parseStringArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                fn ($item) => trim((string) $item),
                $value
            )));
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return self::parseStringArray($decoded);
            }

            return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value))));
        }

        return [];
    }

    private static function parseHowToUse(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(function ($item) {
                if (!is_array($item)) {
                    return null;
                }
                $title = trim((string) ($item['title'] ?? ''));
                if ($title === '') {
                    return null;
                }

                return [
                    'title' => $title,
                    'description' => trim((string) ($item['description'] ?? '')),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
