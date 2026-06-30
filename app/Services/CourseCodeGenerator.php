<?php

namespace App\Services;

use App\Models\Course;

class CourseCodeGenerator
{
    public const PREFIXES = ['ENG', 'FRE', 'BBA', 'IELTS', 'BUS', 'GEN'];

    public static function prefixFromTitle(string $title): string
    {
        $upper = strtoupper($title);
        foreach (self::PREFIXES as $prefix) {
            if (str_contains($upper, $prefix)) {
                return $prefix;
            }
        }

        $words = preg_split('/\s+/', preg_replace('/[^A-Za-z0-9\s]/', ' ', $title));
        $letters = '';
        foreach ($words as $word) {
            $clean = preg_replace('/[^A-Za-z]/', '', $word);
            if ($clean !== '') {
                $letters .= strtoupper(substr($clean, 0, 1));
            }
            if (strlen($letters) >= 3) {
                break;
            }
        }

        return $letters !== '' ? substr($letters, 0, 4) : 'GEN';
    }

    public static function generate(?string $prefix = null, ?string $title = null): string
    {
        $base = strtoupper(preg_replace('/[^A-Z0-9]/', '', $prefix ?? ''));
        if ($base === '' && $title) {
            $base = self::prefixFromTitle($title);
        }
        if ($base === '') {
            $base = 'GEN';
        }
        $base = substr($base, 0, 6);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $suffix = str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT);
            $code = $base . $suffix;
            if (!Course::query()->where('course_code', $code)->exists()) {
                return $code;
            }
        }

        return $base . strtoupper(substr(uniqid(), -4));
    }
}
