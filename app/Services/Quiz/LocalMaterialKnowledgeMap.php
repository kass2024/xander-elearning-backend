<?php

namespace App\Services\Quiz;

use Illuminate\Support\Str;

/**
 * Builds a lightweight knowledge map from extracted document text — no AI API calls.
 */
class LocalMaterialKnowledgeMap
{
    /** @var array<int, string> */
    protected array $stopWords = [
        'about', 'after', 'also', 'been', 'being', 'between', 'could', 'each', 'from',
        'have', 'into', 'more', 'other', 'shall', 'should', 'such', 'than', 'that',
        'their', 'there', 'these', 'they', 'this', 'those', 'through', 'under',
        'very', 'were', 'what', 'when', 'where', 'which', 'while', 'will', 'with',
        'would', 'your', 'chapter', 'section', 'module', 'course', 'lesson', 'page',
    ];

    /**
     * @return array{map: array<string, mixed>, provider: string}
     */
    public function build(string $text, string $label): array
    {
        $text = trim($text);
        $lines = preg_split('/\r\n|\r|\n/u', $text) ?: [];
        $paragraphs = preg_split("/\n{2,}/u", $text) ?: [];

        $modules = $this->extractModuleHeadings($lines);
        $headings = $modules !== [] ? $modules : $this->extractHeadings($lines, $label);
        $headings = $this->cleanTopicHeadings($headings, $label);
        $definitions = $this->extractDefinitions($lines);
        $keyConcepts = $this->extractKeyTerms($text, $headings);
        $outcomes = $this->extractLearningOutcomes($lines);
        $subtopics = array_slice($headings, 1, 12);

        return [
            'map' => [
                'main_topics' => array_values(array_slice($headings, 0, 8)),
                'subtopics' => array_values(array_unique($subtopics)),
                'key_concepts' => array_values(array_slice($keyConcepts, 0, 20)),
                'definitions' => array_slice($definitions, 0, 15),
                'learning_outcomes' => array_values(array_slice($outcomes, 0, 8)),
                'difficulty_level' => $this->estimateDifficulty($text),
                'bloom_levels_present' => ['remember', 'understand', 'apply'],
            ],
            'provider' => 'local',
        ];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    protected function extractHeadings(array $lines, string $label): array
    {
        $headings = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strlen($line) > 140) {
                continue;
            }

            if (preg_match('/^(chapter|section|module|unit|part|topic)\s+[\d\.]+[:\.]?\s*(.+)$/iu', $line, $m)) {
                $headings[] = trim($m[0]);
                continue;
            }

            if (preg_match('/^\d+[\.\)]\s+(.{4,120})$/u', $line, $m)) {
                $candidate = trim($m[1]);
                if (!$this->looksLikeSentence($candidate)) {
                    $headings[] = $candidate;
                }
                continue;
            }

            if (preg_match('/^[A-Z0-9][A-Za-z0-9\s\-:&,\(\)\/]{3,100}$/u', $line)
                && !str_ends_with($line, '.')
                && !str_ends_with($line, ',')
                && str_word_count($line) <= 14) {
                $headings[] = $line;
            }
        }

        $headings = array_values(array_unique(array_filter($headings, fn ($h) => strlen($h) >= 4)));

        return $headings;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    protected function extractModuleHeadings(array $lines): array
    {
        $modules = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strlen($line) > 160) {
                continue;
            }

            if (preg_match('/^module\s+\d+\s*[:\-–—]\s*.+/iu', $line)) {
                $modules[] = $line;
            }
        }

        return array_values(array_unique($modules));
    }

    /**
     * @param  array<int, string>  $headings
     * @return array<int, string>
     */
    protected function cleanTopicHeadings(array $headings, string $label): array
    {
        $clean = [];

        foreach ($headings as $heading) {
            $heading = trim((string) $heading);
            if ($heading === '' || strlen($heading) < 3) {
                continue;
            }
            if (preg_match('/\.pdf$/i', $heading)) {
                continue;
            }
            if (preg_match('/^(learning objectives|key points|read the lesson|take notes)/i', $heading)) {
                continue;
            }
            if (str_contains(strtolower($heading), 'sample study guide is intentionally')) {
                continue;
            }
            if (strtolower($heading) === strtolower(trim($label))) {
                continue;
            }

            $clean[] = $heading;
        }

        return array_values(array_unique($clean));
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, array{term: string, definition: string}>
     */
    protected function extractDefinitions(array $lines): array
    {
        $definitions = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^([A-Za-z][A-Za-z0-9\s\-]{1,60})\s*[:\-–—]\s*(.{10,400})$/u', $line, $m)) {
                $definitions[] = [
                    'term' => trim($m[1]),
                    'definition' => trim($m[2]),
                ];
            }
        }

        return $definitions;
    }

    /**
     * @param  array<int, string>  $seedHeadings
     * @return array<int, string>
     */
    protected function extractKeyTerms(string $text, array $seedHeadings): array
    {
        $normalized = strtolower($text);
        $normalized = preg_replace('/[^a-z0-9\s]/u', ' ', $normalized) ?? $normalized;
        $words = preg_split('/\s+/u', $normalized) ?: [];
        $freq = [];

        foreach ($words as $word) {
            if (strlen($word) < 5 || in_array($word, $this->stopWords, true)) {
                continue;
            }
            $freq[$word] = ($freq[$word] ?? 0) + 1;
        }

        arsort($freq);
        $terms = array_keys(array_filter($freq, fn ($count) => $count >= 2));
        $terms = array_slice($terms, 0, 25);

        foreach ($seedHeadings as $heading) {
            $terms[] = Str::title(trim($heading));
        }

        return array_values(array_unique(array_filter($terms)));
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    protected function extractLearningOutcomes(array $lines): array
    {
        $outcomes = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(learning outcome|objective|students will|you will|by the end)/iu', $line)) {
                $outcomes[] = $line;
            }
        }

        return $outcomes;
    }

    protected function estimateDifficulty(string $text): string
    {
        $words = str_word_count($text);
        $sentences = max(1, preg_match_all('/[.!?]+/u', $text) ?: 1);
        $avgWords = $words / $sentences;

        if ($words > 5000 || $avgWords > 28) {
            return 'advanced';
        }
        if ($words > 1500 || $avgWords > 22) {
            return 'intermediate';
        }

        return 'beginner';
    }

    protected function looksLikeSentence(string $text): bool
    {
        return str_word_count($text) > 12 && str_ends_with($text, '.');
    }
}
