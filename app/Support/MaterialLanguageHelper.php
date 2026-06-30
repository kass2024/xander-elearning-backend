<?php

namespace App\Support;

/**
 * Detect dominant language from uploaded study material text.
 */
class MaterialLanguageHelper
{
    /** @var array<string, array{label: string, markers: array<int, string>, words: array<int, string>}> */
    protected static array $profiles = [
        'fr' => [
            'label' => 'French',
            'markers' => ['à', 'â', 'ä', 'é', 'è', 'ê', 'ë', 'î', 'ï', 'ô', 'ù', 'û', 'ü', 'ç', 'œ', 'æ'],
            'words' => [
                'le', 'la', 'les', 'des', 'une', 'dans', 'pour', 'avec', 'est', 'sont', 'vous', 'nous',
                'compréhension', 'comprhension', 'module', 'leçon', 'lecon', 'grammaire', 'vocabulaire',
                'écoute', 'ecoute', 'parler', 'écrire', 'ecrire', 'passé', 'passe', 'présent', 'present',
            ],
        ],
        'en' => [
            'label' => 'English',
            'markers' => [],
            'words' => [
                'the', 'and', 'with', 'for', 'are', 'is', 'this', 'that', 'module', 'chapter', 'lesson',
                'will', 'from', 'your', 'have', 'reading', 'listening', 'grammar', 'vocabulary', 'writing',
            ],
        ],
        'es' => [
            'label' => 'Spanish',
            'markers' => ['á', 'é', 'í', 'ó', 'ú', 'ñ', '¿', '¡'],
            'words' => ['el', 'la', 'los', 'las', 'de', 'que', 'para', 'con', 'módulo', 'modulo', 'lección', 'leccion'],
        ],
        'de' => [
            'label' => 'German',
            'markers' => ['ä', 'ö', 'ü', 'ß'],
            'words' => ['der', 'die', 'das', 'und', 'für', 'fur', 'mit', 'ist', 'modul', 'kapitel', 'lektion'],
        ],
    ];

    /**
     * @return array{code: string, label: string, instruction: string}
     */
    public static function detectFromText(string $text, ?string $topic = null): array
    {
        $sample = mb_strtolower(trim($text . ' ' . (string) $topic));
        $sample = mb_substr($sample, 0, 8000);

        if ($sample === '') {
            return self::pack('en');
        }

        $scores = [];
        foreach (self::$profiles as $code => $profile) {
            $score = 0;
            foreach ($profile['markers'] as $marker) {
                $score += mb_substr_count($sample, $marker) * 2;
            }
            foreach ($profile['words'] as $word) {
                $score += preg_match_all('/\b' . preg_quote($word, '/') . '\b/u', $sample) ?: 0;
            }
            $scores[$code] = $score;
        }

        arsort($scores);
        $topCode = array_key_first($scores) ?: 'en';
        $topScore = (int) ($scores[$topCode] ?? 0);

        if ($topScore < 3) {
            $topCode = 'en';
        }

        return self::pack($topCode);
    }

    public static function label(string $code): string
    {
        return self::$profiles[$code]['label'] ?? ucfirst($code);
    }

    public static function promptInstruction(string $code): string
    {
        $label = self::label($code);

        return "LANGUAGE RULE: Write ALL questions, answer options, explanations, and learner feedback in {$label}. "
            . 'Use the same language as the source study material — do NOT translate into another language.';
    }

    public static function markingFeedbackInstruction(string $code): string
    {
        $label = self::label($code);

        return "Write overall_feedback and per-question feedback in {$label}, matching the question language.";
    }

    /**
     * @return array{code: string, label: string, instruction: string}
     */
    protected static function pack(string $code): array
    {
        if (!isset(self::$profiles[$code])) {
            $code = 'en';
        }

        return [
            'code' => $code,
            'label' => self::$profiles[$code]['label'],
            'instruction' => self::promptInstruction($code),
        ];
    }
}
