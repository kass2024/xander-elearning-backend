<?php

namespace App\Services\Quiz;

use App\Models\CourseMaterial;
use App\Models\QuizMaterialAnalysis;
use App\Services\MaterialDocumentReader;
use App\Support\QuizMaterialHelper;
use App\Support\MaterialLanguageHelper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QuizMaterialAnalysisService
{
    public function __construct(
        protected MaterialDocumentReader $documentReader,
        protected QuizDocumentEngine $documentEngine,
        protected QuizEmbeddingService $embeddings,
        protected LocalMaterialKnowledgeMap $localKnowledgeMap,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(CourseMaterial $material, bool $force = false, bool $forceAiTopics = false): array
    {
        $text = $this->documentReader->readMaterialText($material);
        if ($text === null || trim($text) === '') {
            $fetchError = $this->documentReader->lastFetchError();
            $title = $material->title ?? 'PDF';

            throw new \RuntimeException(
                $fetchError
                    ? "Could not download \"{$title}\" from pCloud: {$fetchError}"
                    : "Could not extract readable text from \"{$title}\". The PDF may be scanned/image-only or corrupted."
            );
        }

        $hash = hash('sha256', $text);
        $existing = QuizMaterialAnalysis::query()
            ->where('course_material_id', $material->id)
            ->where('content_hash', $hash)
            ->first();

        if ($existing && !$force) {
            $useCache = !$forceAiTopics;
            if ($forceAiTopics) {
                $cachedMap = is_array($existing->knowledge_map) ? $existing->knowledge_map : [];
                $cachedTopics = array_merge(
                    is_array($cachedMap['main_topics'] ?? null) ? $cachedMap['main_topics'] : [],
                    is_array($cachedMap['subtopics'] ?? null) ? $cachedMap['subtopics'] : [],
                );
                $validCached = array_filter($cachedTopics, fn ($t) => $this->isValidExtractedTopic(trim((string) $t)));
                $useCache = in_array($existing->analysis_provider, ['gemini', 'claude'], true)
                    && count($validCached) > 0;
            }
            if ($useCache) {
                return $this->formatAnalysisResponse($existing, $material);
            }
        }

        $label = QuizMaterialHelper::extractTopicLabel($material) ?? ($material->title ?? 'Material');
        $chunks = $this->documentEngine->chunkText($text, $label, (int) $material->id);
        $knowledgeMap = $this->buildKnowledgeMap($text, $label, $forceAiTopics);
        $provider = $knowledgeMap['provider'] ?? 'local';
        $aiWarnings = $knowledgeMap['ai_warnings'] ?? [];

        $chunkEmbeddings = [];
        $embeddingModel = null;
        if ($this->shouldIndexEmbeddings() && $this->embeddings->isAvailable()) {
            $chunkEmbeddings = $this->embeddings->embedChunks($chunks);
            $embeddingModel = config('services.quiz_ai.embedding_model', 'text-embedding-004');
        }

        $record = QuizMaterialAnalysis::updateOrCreate(
            [
                'course_material_id' => $material->id,
                'content_hash' => $hash,
            ],
            [
                'knowledge_map' => $knowledgeMap['map'] ?? [],
                'chunks' => $chunks,
                'chunk_embeddings' => $chunkEmbeddings ?: null,
                'embedding_model' => $embeddingModel,
                'word_count' => str_word_count($text),
                'analysis_provider' => $provider,
                'analyzed_at' => now(),
            ]
        );

        return $this->formatAnalysisResponse($record, $material, $aiWarnings);
    }

    /**
     * Cached knowledge map only — never triggers Claude/embeddings (fast path for quiz generation).
     *
     * @return array<string, mixed>|null
     */
    public function getCachedKnowledgeMap(CourseMaterial $material): ?array
    {
        $text = $this->documentReader->readMaterialText($material);
        if ($text === null || trim($text) === '') {
            return null;
        }

        $existing = QuizMaterialAnalysis::query()
            ->where('course_material_id', $material->id)
            ->where('content_hash', hash('sha256', $text))
            ->first();

        if (!$existing || !is_array($existing->knowledge_map) || $existing->knowledge_map === []) {
            return null;
        }

        return $existing->knowledge_map;
    }

    /**
     * Extract quiz topics from course PDFs using Gemini (cached after first run).
     *
     * @param  iterable<CourseMaterial>  $materials
     * @return array{groups: array<int, array<string, mixed>>, analyzed: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>}
     */
    public function buildCourseTopicGroupsFromMaterials(iterable $materials): array
    {
        $groups = [];
        $analyzed = [];
        $errors = [];

        foreach ($materials as $material) {
            if (!$material instanceof CourseMaterial || !QuizMaterialHelper::isPdfMaterial($material)) {
                continue;
            }

            $title = (string) ($material->title ?? 'PDF');

            try {
                $analysis = $this->analyze($material, false, true);
            } catch (\Throwable $e) {
                Log::warning('PDF topic extraction failed', [
                    'material_id' => $material->id,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = [
                    'material_id' => $material->id,
                    'material_title' => $title,
                    'code' => 'pdf_read_failed',
                    'message' => $e->getMessage(),
                ];
                continue;
            }

            $map = is_array($analysis['knowledge_map'] ?? null) ? $analysis['knowledge_map'] : [];
            $topicLabels = array_merge(
                is_array($map['main_topics'] ?? null) ? $map['main_topics'] : [],
                is_array($map['subtopics'] ?? null) ? $map['subtopics'] : [],
            );

            $added = 0;
            foreach ($topicLabels as $topicLabel) {
                $topicLabel = trim((string) $topicLabel);
                if (!$this->isValidExtractedTopic($topicLabel)) {
                    continue;
                }

                $key = strtolower($topicLabel);
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'label' => $topicLabel,
                        'material_ids' => [],
                        'materials' => [],
                        'source' => $analysis['analysis_provider'] ?? 'ai',
                        'source_material_id' => $material->id,
                    ];
                }

                if (!in_array($material->id, $groups[$key]['material_ids'], true)) {
                    $groups[$key]['material_ids'][] = $material->id;
                    $groups[$key]['materials'][] = QuizMaterialHelper::materialSummary($material);
                    $added++;
                }
            }

            $entry = [
                'material_id' => $material->id,
                'material_title' => $title,
                'provider' => $analysis['analysis_provider'] ?? null,
                'topics_extracted' => $added,
            ];

            if (!empty($analysis['ai_warnings'])) {
                $entry['ai_warnings'] = $analysis['ai_warnings'];
            }

            $analyzed[] = $entry;

            if ($added === 0) {
                $errors[] = [
                    'material_id' => $material->id,
                    'material_title' => $title,
                    'code' => 'no_topics_found',
                    'message' => 'PDF was read but no module/chapter topics could be extracted. Check that the PDF contains selectable text (not a scanned image).',
                ];
            }
        }

        return [
            'groups' => array_values($groups),
            'analyzed' => $analyzed,
            'errors' => $errors,
        ];
    }

    protected function isValidExtractedTopic(string $label): bool
    {
        if ($label === '' || strlen($label) < 3) {
            return false;
        }
        if (preg_match('/\.pdf$/i', $label)) {
            return false;
        }
        if (preg_match('/^(learning objectives|key points|read the lesson|take notes)/i', $label)) {
            return false;
        }
        if (str_contains(strtolower($label), 'sample study guide is intentionally')) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatAnalysisResponse(QuizMaterialAnalysis $record, CourseMaterial $material, array $aiWarnings = []): array
    {
        $map = is_array($record->knowledge_map) ? $record->knowledge_map : [];

        $response = [
            'material_id' => $material->id,
            'material_title' => $material->title,
            'word_count' => $record->word_count,
            'chunk_count' => count($record->chunks ?? []),
            'analysis_provider' => $record->analysis_provider,
            'analyzed_at' => $record->analyzed_at?->toIso8601String(),
            'knowledge_map' => $map,
            'topics' => $map['main_topics'] ?? [],
            'learning_outcomes' => $map['learning_outcomes'] ?? [],
            'difficulty_level' => $map['difficulty_level'] ?? 'intermediate',
            'embeddings_indexed' => is_array($record->chunk_embeddings) && count($record->chunk_embeddings) > 0,
            'embedding_model' => $record->embedding_model,
        ];

        if ($aiWarnings !== []) {
            $response['ai_warnings'] = $aiWarnings;
        }

        return $response;
    }

    /**
     * @return array{map: array<string, mixed>, provider: string, ai_warnings?: array<int, string>}
     */
    protected function buildKnowledgeMap(string $text, string $label, bool $forceAi = false): array
    {
        if ($forceAi || filter_var(config('services.quiz_ai.use_ai_knowledge_map', false), FILTER_VALIDATE_BOOL)) {
            return $this->buildKnowledgeMapWithAi($text, $label);
        }

        return $this->localKnowledgeMap->build($text, $label);
    }

    protected function shouldIndexEmbeddings(): bool
    {
        return filter_var(config('services.quiz_ai.enable_embeddings', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * Optional AI knowledge map — costs API credits; disabled by default.
     *
     * @return array{map: array<string, mixed>, provider: string, ai_warnings?: array<int, string>}
     */
    protected function buildKnowledgeMapWithAi(string $text, string $label): array
    {
        $excerpt = substr($text, 0, min((int) config('services.quiz_ai.max_material_chars', 18000), 12000));
        $language = MaterialLanguageHelper::detectFromText($text, $label);
        $langRule = $language['instruction'];
        $prompt = <<<PROMPT
Analyze this uploaded study material and return JSON only.

Material title: {$label}

Content:
{$excerpt}

{$langRule}
Topic labels must stay in the same language as the document (do not translate module/chapter titles).

Return:
{
  "main_topics": ["Module/chapter titles and major sections from the document — NOT the filename"],
  "subtopics": ["Specific lesson topics, skills, or themes inside each module"],
  "key_concepts": ["..."],
  "definitions": [{"term":"...","definition":"..."}],
  "learning_outcomes": ["..."],
  "difficulty_level": "beginner|intermediate|advanced",
  "bloom_levels_present": ["remember","understand","apply","analyze","evaluate","create"]
}

Rules:
- Extract real module/chapter/topic names from the PDF content (e.g. "Module 1: Compréhension orale", "Les temps du passé").
- Do NOT use the file name as a topic.
- Use ONLY information from the content. Do not add external knowledge.
PROMPT;

        $preferGemini = filter_var(config('services.quiz_ai.prefer_gemini_for_speed', true), FILTER_VALIDATE_BOOL);
        $raw = null;
        $provider = 'local';
        $warnings = [];

        if ($preferGemini) {
            $gemini = $this->callGemini($prompt, 1200);
            if ($gemini['text'] !== null) {
                $raw = $gemini['text'];
                $provider = 'gemini';
            } elseif ($gemini['error']) {
                $warnings[] = 'Gemini: ' . $gemini['error'];
            }

            if ($raw === null) {
                $claude = $this->callClaude($prompt, 1200);
                if ($claude['text'] !== null) {
                    $raw = $claude['text'];
                    $provider = 'claude';
                } elseif ($claude['error']) {
                    $warnings[] = 'Claude: ' . $claude['error'];
                }
            }
        } else {
            $claude = $this->callClaude($prompt, 1200);
            if ($claude['text'] !== null) {
                $raw = $claude['text'];
                $provider = 'claude';
            } elseif ($claude['error']) {
                $warnings[] = 'Claude: ' . $claude['error'];
            }

            if ($raw === null) {
                $gemini = $this->callGemini($prompt, 1200);
                if ($gemini['text'] !== null) {
                    $raw = $gemini['text'];
                    $provider = 'gemini';
                } elseif ($gemini['error']) {
                    $warnings[] = 'Gemini: ' . $gemini['error'];
                }
            }
        }

        if ($raw === null) {
            $local = $this->localKnowledgeMap->build($text, $label);
            if ($warnings !== []) {
                $local['ai_warnings'] = $warnings;
            }

            return $local;
        }

        $decoded = $this->parseJson($raw);
        $topicLabels = array_merge(
            is_array($decoded['main_topics'] ?? null) ? $decoded['main_topics'] : [],
            is_array($decoded['subtopics'] ?? null) ? $decoded['subtopics'] : [],
        );
        $validTopics = array_filter($topicLabels, fn ($t) => $this->isValidExtractedTopic(trim((string) $t)));

        if ($validTopics === []) {
            $warnings[] = 'AI returned no usable module/chapter topics — using local PDF text extraction instead.';
            $local = $this->localKnowledgeMap->build($text, $label);
            if ($warnings !== []) {
                $local['ai_warnings'] = $warnings;
            }

            return $local;
        }

        $result = ['map' => $decoded, 'provider' => $provider];
        if ($warnings !== []) {
            $result['ai_warnings'] = $warnings;
        }

        return $result;
    }

    /**
     * @deprecated Use buildKnowledgeMapWithAi() when QUIZ_AI_USE_AI_KNOWLEDGE_MAP=true
     * @return array{map: array<string, mixed>, provider: string}
     */
    protected function buildKnowledgeMapWithClaude(string $text, string $label): array
    {
        return $this->buildKnowledgeMapWithAi($text, $label);
    }

    /**
     * @return array{text: ?string, error: ?string}
     */
    protected function callClaude(string $prompt, int $maxTokens): array
    {
        $key = config('services.anthropic.api_key');
        if (!$key) {
            return ['text' => null, 'error' => 'Anthropic API key is not configured (ANTHROPIC_API_KEY).'];
        }

        $model = config('services.quiz_ai.claude_generation_model')
            ?: config('services.anthropic.model', 'claude-sonnet-4-6');

        try {
            $response = Http::timeout(60)->withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'temperature' => 0.2,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

            if (!$response->successful()) {
                $message = $this->extractHttpErrorMessage($response->json(), $response->body(), $response->status());

                return ['text' => null, 'error' => "Claude API HTTP {$response->status()}: {$message}"];
            }

            foreach ($response->json('content') ?? [] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text = trim((string) ($block['text'] ?? ''));

                    return $text !== '' ? ['text' => $text, 'error' => null] : ['text' => null, 'error' => 'Claude returned an empty response.'];
                }
            }

            return ['text' => null, 'error' => 'Claude returned no text content.'];
        } catch (\Throwable $e) {
            Log::warning('Material analysis Claude failed', ['error' => $e->getMessage()]);

            return ['text' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{text: ?string, error: ?string}
     */
    protected function callGemini(string $prompt, int $maxTokens): array
    {
        $key = env('GOOGLE_AI_API_KEY') ?: env('GEMINI_API_KEY');
        if (!is_string($key) || trim($key, " \t\"'") === '') {
            return ['text' => null, 'error' => 'Google AI API key is not configured (GOOGLE_AI_API_KEY or GEMINI_API_KEY).'];
        }

        $model = config('services.quiz_ai.generation_model') ?: config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . trim($key, " \t\"'");

        try {
            $response = Http::timeout(60)->post($url, [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => $maxTokens,
                    'responseMimeType' => 'application/json',
                ],
            ]);

            if (!$response->successful()) {
                $message = $this->extractHttpErrorMessage($response->json(), $response->body(), $response->status());

                return ['text' => null, 'error' => "Gemini API HTTP {$response->status()}: {$message}"];
            }

            $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));

            return $text !== '' ? ['text' => $text, 'error' => null] : ['text' => null, 'error' => 'Gemini returned an empty response.'];
        } catch (\Throwable $e) {
            Log::warning('Material analysis Gemini failed', ['error' => $e->getMessage()]);

            return ['text' => null, 'error' => $e->getMessage()];
        }
    }

    protected function extractHttpErrorMessage(mixed $json, string $body, int $status): string
    {
        if (is_array($json)) {
            $message = data_get($json, 'error.message')
                ?? data_get($json, 'error.status')
                ?? data_get($json, 'message');
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        $snippet = trim(preg_replace('/\s+/', ' ', substr($body, 0, 240)) ?? '');

        return $snippet !== '' ? $snippet : "Request failed with HTTP {$status}.";
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseJson(string $raw): array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false) {
            $raw = substr($raw, $start, $end - $start + 1);
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
