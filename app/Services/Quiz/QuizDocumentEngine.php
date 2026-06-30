<?php

namespace App\Services\Quiz;

use App\Models\CourseMaterial;
use App\Models\QuizMaterialAnalysis;
use App\Services\MaterialDocumentReader;
use App\Support\QuizMaterialHelper;
use Illuminate\Support\Collection;

class QuizDocumentEngine
{
    public function __construct(
        protected MaterialDocumentReader $documentReader,
        protected QuizEmbeddingService $embeddings,
    ) {
    }

    /**
     * @param  Collection<int, CourseMaterial>  $materials
     * @return array{context: string, chunks: array<int, array<string, mixed>>, word_count: int, content_hash: string, retrieval: string}
     */
    public function buildRagContext(Collection $materials, string $topic, int $questionCount, bool $fastMode = true): array
    {
        $allChunks = [];
        $combinedText = '';
        $storedEmbeddings = [];

        foreach ($materials as $material) {
            $text = $this->documentReader->readMaterialText($material);
            if ($text === null || trim($text) === '') {
                continue;
            }

            $label = QuizMaterialHelper::extractTopicLabel($material) ?? ($material->title ?? 'Material');
            $combinedText .= $text . "\n";
            $contentHash = hash('sha256', $text);

            $analysis = QuizMaterialAnalysis::query()
                ->where('course_material_id', $material->id)
                ->where('content_hash', $contentHash)
                ->first();

            if ($fastMode && $analysis && is_array($analysis->chunks) && $analysis->chunks !== []) {
                $materialChunks = $analysis->chunks;
            } else {
                $materialChunks = $this->chunkText($text, $label, (int) $material->id);
            }

            foreach ($materialChunks as $chunk) {
                $allChunks[] = $chunk;
            }

            if ($analysis && is_array($analysis->chunk_embeddings)) {
                foreach ($analysis->chunk_embeddings as $row) {
                    $storedEmbeddings[] = $row;
                }
            }
        }

        if ($allChunks === []) {
            return [
                'context' => '',
                'chunks' => [],
                'word_count' => 0,
                'content_hash' => '',
                'retrieval' => 'none',
            ];
        }

        $limit = min(8, max(3, (int) ceil($questionCount * 0.5)));
        $retrieval = 'keyword';
        $selected = $this->retrieveRelevantChunks($allChunks, $topic, $limit);

        if (!$fastMode || $storedEmbeddings !== []) {
            if ($this->embeddings->isAvailable() && $storedEmbeddings !== []) {
                $semantic = $this->embeddings->semanticRetrieve($storedEmbeddings, $allChunks, $topic, $limit);
                if ($semantic !== []) {
                    $selected = $semantic;
                    $retrieval = 'semantic';
                }
            }
        }

        $context = collect($selected)->map(function (array $chunk) {
            return '[Section: ' . ($chunk['section'] ?? 'Content') . "]\n" . ($chunk['text'] ?? '');
        })->implode("\n\n---\n\n");

        $configuredMax = (int) config('services.quiz_ai.max_material_chars', 18000);
        $perQuestion = (int) config('services.quiz_ai.fast_context_chars_per_question', 900);
        $maxChars = $fastMode
            ? min($configuredMax, max(4000, $questionCount * $perQuestion))
            : $configuredMax;
        $context = \Illuminate\Support\Str::limit($context, $maxChars, "\n\n[Truncated for model context.]");

        return [
            'context' => $context,
            'chunks' => $selected,
            'word_count' => str_word_count($combinedText),
            'content_hash' => hash('sha256', $combinedText),
            'retrieval' => $retrieval,
        ];
    }

    /**
     * @return array<int, array{id: string, section: string, text: string, material_id: int, index: int}>
     */
    public function chunkText(string $text, string $sectionLabel, int $materialId, int $chunkSize = 1400, int $overlap = 180): array
    {
        $text = trim(preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text);
        if ($text === '') {
            return [];
        }

        $paragraphs = preg_split("/\n{2,}/u", $text) ?: [$text];
        $chunks = [];
        $buffer = '';
        $index = 0;

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (strlen($buffer) + strlen($paragraph) + 2 <= $chunkSize) {
                $buffer = $buffer === '' ? $paragraph : $buffer . "\n\n" . $paragraph;
                continue;
            }

            if ($buffer !== '') {
                $chunks[] = $this->makeChunk($buffer, $sectionLabel, $materialId, $index++);
            }

            if (strlen($paragraph) <= $chunkSize) {
                $buffer = $paragraph;
                continue;
            }

            $offset = 0;
            $len = strlen($paragraph);
            while ($offset < $len) {
                $piece = substr($paragraph, $offset, $chunkSize);
                $chunks[] = $this->makeChunk($piece, $sectionLabel, $materialId, $index++);
                $offset += max(1, $chunkSize - $overlap);
            }
            $buffer = '';
        }

        if ($buffer !== '') {
            $chunks[] = $this->makeChunk($buffer, $sectionLabel, $materialId, $index++);
        }

        return $chunks;
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunks
     * @return array<int, array<string, mixed>>
     */
    public function retrieveRelevantChunks(array $chunks, string $topic, int $limit): array
    {
        if ($chunks === []) {
            return [];
        }

        $topicTerms = $this->tokenize($topic);
        $scored = [];

        foreach ($chunks as $chunk) {
            $text = (string) ($chunk['text'] ?? '');
            $chunkTerms = $this->tokenize($text . ' ' . ($chunk['section'] ?? ''));
            $overlap = count(array_intersect($topicTerms, $chunkTerms));
            $score = $overlap * 3 + min(5, (int) (strlen($text) / 800));

            if ($score === 0 && $topicTerms !== []) {
                foreach ($topicTerms as $term) {
                    if ($term !== '' && stripos($text, $term) !== false) {
                        $score += 2;
                    }
                }
            }

            $scored[] = ['chunk' => $chunk, 'score' => $score];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $top = array_slice($scored, 0, $limit);
        if (array_sum(array_column($top, 'score')) === 0) {
            return array_slice($chunks, 0, $limit);
        }

        return array_values(array_map(fn ($row) => $row['chunk'], $top));
    }

    /** @return array<string> */
    protected function tokenize(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]/u', ' ', $text) ?? $text;
        $parts = preg_split('/\s+/u', $text) ?: [];

        return array_values(array_unique(array_filter($parts, fn ($w) => strlen($w) >= 3)));
    }

    protected function makeChunk(string $text, string $section, int $materialId, int $index): array
    {
        return [
            'id' => 'm' . $materialId . '_c' . $index,
            'section' => \Illuminate\Support\Str::limit($section, 120, ''),
            'text' => trim($text),
            'material_id' => $materialId,
            'index' => $index,
        ];
    }
}
