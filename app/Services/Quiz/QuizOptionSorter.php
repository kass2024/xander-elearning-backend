<?php

namespace App\Services\Quiz;

class QuizOptionSorter
{
    /**
     * Sort MCQ options alphabetically by leading label (A, B, C…) or by option text.
     *
     * @param  array<int, mixed>  $options
     * @return array<int, string>
     */
    public static function sort(array $options): array
    {
        $options = array_values(array_map(fn ($option) => (string) $option, $options));
        usort($options, function (string $a, string $b): int {
            $keyA = self::sortKey($a);
            $keyB = self::sortKey($b);
            if ($keyA !== $keyB) {
                return $keyA <=> $keyB;
            }

            return strcasecmp($a, $b);
        });

        return $options;
    }

    public static function sortKey(string $option): string
    {
        $option = trim($option);
        if (preg_match('/^([A-Z])[\).:\-\s]/iu', $option, $matches)) {
            return strtoupper($matches[1]);
        }

        return mb_strtolower($option);
    }
}
