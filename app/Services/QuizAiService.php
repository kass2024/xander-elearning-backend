<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Services\Quiz\QuizDocumentEngine;
use App\Services\Quiz\QuizMaterialAnalysisService;
use App\Services\Quiz\QuizQuestionValidator;
use App\Support\QuizMaterialHelper;
use App\Support\MaterialLanguageHelper;
use App\Services\Quiz\QuizAnswerMatcher;
use App\Services\Quiz\QuizOptionSorter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class QuizAiService
{
    protected ?string $lastAiError = null;

    /** @var array<string> */
    protected array $supportedTypes = [
        'multiple_choice',
        'multiple_response',
        'true_false',
        'matching',
        'fill_blank',
        'short_answer',
        'long_answer',
        'essay',
        'case_study',
        'problem_solving',
        'scenario',
        'hots',
        'oral_listen',
    ];

    public function __construct(
        protected MaterialDocumentReader $documentReader,
        protected QuizDocumentEngine $documentEngine,
        protected QuizMaterialAnalysisService $analysisService,
        protected QuizQuestionValidator $questionValidator,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->hasClaude() || $this->hasGemini();
    }

    public function hasClaude(): bool
    {
        return (bool) config('services.anthropic.api_key');
    }

    public function hasGemini(): bool
    {
        return $this->geminiApiKeys() !== [];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{questions: array<int, array>, provider: string, knowledge_map?: array, rejected?: array, insufficient?: bool}
     */
    public function generateQuestions(
        Course $course,
        string $topic,
        int $count = 5,
        string $difficulty = 'medium',
        ?int $materialId = null,
        array $options = []
    ): array {
        $count = $this->resolveQuestionCount($count, $options);
        $materials = $this->resolveContextMaterials($course, $topic, $materialId);
        $rag = $this->documentEngine->buildRagContext($materials, $topic, $count, true);

        if ($rag['context'] === '' || ($rag['word_count'] ?? 0) < 40) {
            throw new \RuntimeException(
                'Insufficient information in uploaded material. Could not extract enough readable text from the document(s).'
            );
        }

        $knowledgeMap = null;
        $primaryMaterial = $materials->first();
        if ($primaryMaterial instanceof CourseMaterial) {
            $knowledgeMap = $this->analysisService->getCachedKnowledgeMap($primaryMaterial);
        }

        $context = $this->formatGenerationContext($course, $topic, $materials, $rag['context'], $knowledgeMap);

        $language = MaterialLanguageHelper::detectFromText($rag['context'], $topic);
        $options['assessment_language'] = $language['code'];
        $options['assessment_language_label'] = $language['label'];
        $options['language_instruction'] = $language['instruction'];

        $batchSize = max(5, (int) config('services.quiz_ai.generation_batch_size', 10));
        $useParallel = filter_var(config('services.quiz_ai.parallel_generation_batches', true), FILTER_VALIDATE_BOOL)
            && $count > $batchSize
            && $this->hasGemini();

        if ($useParallel) {
            $result = $this->generateQuestionsInParallelBatches(
                $course,
                $topic,
                $count,
                $difficulty,
                $context,
                $options,
                $batchSize,
                $knowledgeMap,
                $rag['content_hash'] ?? null,
            );
        } else {
            $result = $this->generateQuestionsSingleCall(
                $course,
                $topic,
                $count,
                $difficulty,
                $context,
                $options,
                $knowledgeMap,
                $rag['content_hash'] ?? null,
            );
        }

        return $this->attachAssessmentLanguage($result, $options);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function attachAssessmentLanguage(array $result, array $options): array
    {
        if (!empty($options['assessment_language'])) {
            $result['assessment_language'] = $options['assessment_language'];
            $result['assessment_language_label'] = $options['assessment_language_label'] ?? MaterialLanguageHelper::label((string) $options['assessment_language']);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $knowledgeMap
     * @return array{questions: array<int, array>, provider: string, knowledge_map?: array, rejected?: array, insufficient?: bool}
     */
    protected function generateQuestionsSingleCall(
        Course $course,
        string $topic,
        int $count,
        string $difficulty,
        string $context,
        array $options,
        ?array $knowledgeMap,
        ?string $contentHash,
    ): array {
        $prompt = $this->generationPrompt($course, $topic, $count, $difficulty, $context, $options);
        $maxTokens = $this->generationMaxTokens($count);

        $raw = null;
        $provider = 'gemini';

        foreach ($this->resolveAiProviderOrder('generation') as $name) {
            if ($name === 'claude' && $this->hasClaude()) {
                $raw = $this->callClaude($prompt, $maxTokens, $this->resolveGenerationModel());
                $provider = 'claude';
            } elseif ($name === 'gemini' && $this->hasGemini()) {
                $raw = $this->callGemini($prompt, $maxTokens, true);
                $provider = 'gemini';
            }

            if ($raw !== null) {
                break;
            }
        }

        if ($raw === null) {
            $detail = $this->lastAiError ?: 'Both Claude and Gemini returned no response.';
            throw new \RuntimeException('AI quiz generation failed: ' . $detail);
        }

        return $this->finalizeGeneratedQuestions($raw, $count, $options, $provider, $knowledgeMap, $contentHash);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $knowledgeMap
     * @return array{questions: array<int, array>, provider: string, knowledge_map?: array, rejected?: array, insufficient?: bool}
     */
    protected function generateQuestionsInParallelBatches(
        Course $course,
        string $topic,
        int $count,
        string $difficulty,
        string $context,
        array $options,
        int $batchSize,
        ?array $knowledgeMap,
        ?string $contentHash,
    ): array {
        $batches = [];
        $remaining = $count;
        while ($remaining > 0) {
            $batchCount = min($batchSize, $remaining);
            $batches[] = $batchCount;
            $remaining -= $batchCount;
        }

        $model = config('services.quiz_ai.generation_model')
            ?: config('services.gemini.model', 'gemini-2.5-flash');
        $keys = $this->geminiApiKeys();
        $key = reset($keys);
        if (!is_string($key) || $key === '') {
            return $this->generateQuestionsSingleCall(
                $course,
                $topic,
                $count,
                $difficulty,
                $context,
                $options,
                $knowledgeMap,
                $contentHash,
            );
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
        $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($batches, $course, $topic, $difficulty, $context, $options, $url) {
            $requests = [];
            foreach ($batches as $index => $batchCount) {
                $prompt = $this->generationPrompt($course, $topic, $batchCount, $difficulty, $context, $options, $index + 1);
                $maxTokens = $this->generationMaxTokens($batchCount);
                $requests[] = $pool->as("batch_{$index}")
                    ->timeout(75)
                    ->connectTimeout(12)
                    ->post($url, [
                        'contents' => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => $this->buildGeminiGenerationConfig($maxTokens, true),
                    ]);
            }

            return $requests;
        });

        $allQuestions = [];
        $rejected = [];

        foreach ($batches as $index => $batchCount) {
            $response = $responses["batch_{$index}"] ?? null;
            if (!$response || !$response->successful()) {
                $status = $response?->status() ?? 0;
                $this->lastAiError = $this->summarizeHttpError('Gemini batch', $status, (string) ($response?->body() ?? ''));
                continue;
            }

            $raw = $this->extractGeminiText(is_array($response->json()) ? $response->json() : null);
            if ($raw === '') {
                continue;
            }

            try {
                $parsed = $this->parseQuestionsJson($raw);
            } catch (\Throwable $e) {
                Log::warning('Gemini batch JSON parse failed', [
                    'batch' => $index,
                    'finish' => data_get($response->json(), 'candidates.0.finishReason'),
                    'error' => $e->getMessage(),
                    'snippet' => substr($raw, 0, 240),
                ]);
                continue;
            }
            if (!empty($parsed['insufficient'])) {
                continue;
            }

            $normalized = $this->normalizeQuestions($parsed['questions'] ?? $parsed, $options);
            $validated = $this->questionValidator->validate($normalized);
            $allQuestions = array_merge($allQuestions, $validated['questions']);
            $rejected = array_merge($rejected, $validated['rejected']);
        }

        if (count($allQuestions) < max(1, (int) ceil($count * 0.5))) {
            throw new \RuntimeException('AI quiz generation failed: ' . ($this->lastAiError ?: 'Parallel batches returned too few questions.'));
        }

        $allQuestions = array_slice($allQuestions, 0, $count);
        foreach ($allQuestions as $i => &$question) {
            $question['id'] = 'q' . ($i + 1);
        }
        unset($question);

        return [
            'questions' => $allQuestions,
            'provider' => 'gemini',
            'knowledge_map' => $knowledgeMap,
            'rejected' => $rejected,
            'content_hash' => $contentHash,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $knowledgeMap
     * @return array{questions: array<int, array>, provider: string, knowledge_map?: array, rejected?: array, insufficient?: bool}
     */
    protected function finalizeGeneratedQuestions(
        string $raw,
        int $count,
        array $options,
        string $provider,
        ?array $knowledgeMap,
        ?string $contentHash,
    ): array {
        $parsed = $this->parseQuestionsJson($raw);

        if (!empty($parsed['insufficient'])) {
            throw new \RuntimeException('Insufficient information in uploaded material.');
        }

        $questions = $this->normalizeQuestions($parsed['questions'] ?? $parsed, $options);
        $validated = $this->questionValidator->validate($questions);
        $questions = $validated['questions'];

        if (count($questions) < max(1, (int) ceil($count * 0.5))) {
            throw new \RuntimeException('Insufficient information in uploaded material.');
        }

        $questions = array_slice($questions, 0, $count);

        return [
            'questions' => $questions,
            'provider' => $provider,
            'knowledge_map' => $knowledgeMap,
            'rejected' => $validated['rejected'],
            'content_hash' => $contentHash,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function markAttempt(array $questions, array $answers, int $passingScore = 70, ?string $assessmentLanguage = null): array
    {
        $results = [];
        $score = 0;
        $maxScore = 0;
        $openItems = [];
        $pendingManual = 0;

        foreach ($questions as $question) {
            $qid = (string) ($question['id'] ?? '');
            $points = (int) ($question['points'] ?? 1);
            $maxScore += $points;
            $type = (string) ($question['type'] ?? 'multiple_choice');
            $studentAnswer = QuizAnswerMatcher::lookupAnswer($answers, $qid);

            if ($type === 'true_false') {
                $results[] = $this->markExact($question, $studentAnswer, $points, $qid);
                $score += $results[array_key_last($results)]['score'];
                continue;
            }

            if ($type === 'multiple_choice') {
                $results[] = $this->markExact($question, $studentAnswer, $points, $qid);
                $score += $results[array_key_last($results)]['score'];
                continue;
            }

            if ($type === 'multiple_response') {
                $results[] = $this->markMultipleResponse($question, $studentAnswer, $points, $qid);
                $score += $results[array_key_last($results)]['score'];
                continue;
            }

            if ($type === 'matching') {
                $decoded = is_array($studentAnswer) ? $studentAnswer : json_decode((string) $studentAnswer, true);
                $results[] = $this->markMatching($question, is_array($decoded) ? $decoded : [], $points, $qid);
                $score += $results[array_key_last($results)]['score'];
                continue;
            }

            if ($type === 'fill_blank') {
                $results[] = $this->markFillBlank($question, $studentAnswer, $points, $qid);
                $score += $results[array_key_last($results)]['score'];
                continue;
            }

            if ($type === 'oral_listen') {
                $prepared = $this->prepareOralAnswer($question, $studentAnswer);
                if ($prepared === null) {
                    $results[] = [
                        'question_id' => $qid,
                        'type' => $type,
                        'correct' => false,
                        'score' => 0,
                        'max_score' => $points,
                        'student_answer' => '',
                        'feedback' => 'No answer provided.',
                        'marked_by' => 'manual',
                        'pending_review' => false,
                    ];
                    continue;
                }

                $pendingManual++;
                $results[] = [
                    'question_id' => $qid,
                    'type' => $type,
                    'correct' => null,
                    'score' => 0,
                    'max_score' => $points,
                    'student_answer' => $prepared['original'],
                    'transcription' => $prepared['transcription'] ?? null,
                    'feedback' => 'Awaiting instructor review.',
                    'marked_by' => 'manual_pending',
                    'pending_review' => true,
                    'response_format' => $question['response_format'] ?? 'text',
                ];
                continue;
            }

            if (trim((string) $studentAnswer) === '') {
                $results[] = [
                    'question_id' => $qid,
                    'type' => $type,
                    'correct' => false,
                    'score' => 0,
                    'max_score' => $points,
                    'student_answer' => '',
                    'feedback' => 'No answer provided.',
                    'marked_by' => 'auto',
                ];
                continue;
            }

            $openItems[] = [
                'question_id' => $qid,
                'type' => $type,
                'question' => $question['question'] ?? '',
                'model_answer' => $question['model_answer'] ?? ($question['correct_answer'] ?? ''),
                'marking_rubric' => $question['marking_rubric'] ?? null,
                'student_answer' => $studentAnswer,
                'points' => $points,
            ];
        }

        $markingProvider = 'auto';
        $overallFeedback = '';
        $analytics = null;

        if ($openItems !== []) {
            $languageCode = $assessmentLanguage;
            if (!$languageCode) {
                $sample = implode(' ', array_map(
                    fn ($item) => (string) (($item['question'] ?? '') . ' ' . ($item['model_answer'] ?? '')),
                    $openItems
                ));
                $languageCode = MaterialLanguageHelper::detectFromText($sample)['code'];
            }

            $aiMark = $this->markOpenAnswersWithAi($openItems, $languageCode);
            $markingProvider = $aiMark['provider'];
            $overallFeedback = $aiMark['overall_feedback'] ?? '';

            foreach ($aiMark['results'] as $row) {
                $score += (int) ($row['score'] ?? 0);
                if (!empty($row['question_id'])) {
                    foreach ($openItems as $item) {
                        if (($item['question_id'] ?? '') === ($row['question_id'] ?? '')) {
                            if (!empty($item['original_answer'])) {
                                $row['student_answer'] = $item['original_answer'];
                            }
                            if (!empty($item['transcription'])) {
                                $row['transcription'] = $item['transcription'];
                            }
                            break;
                        }
                    }
                }
                $results[] = $row;
            }
        }

        $percentage = $maxScore > 0 ? round(($score / $maxScore) * 100, 2) : 0;

        if ($pendingManual > 0) {
            $markingProvider = 'manual';
            $overallFeedback = $openItems === []
                ? 'Submitted. Your instructor will review your oral responses and publish your final score.'
                : 'Submitted. Some answers were auto-marked; oral responses await instructor review.';
        }

        if ($pendingManual === 0 && ($this->hasGemini() || $this->hasClaude())) {
            $analytics = $this->buildPersonalizedFeedback($results, $percentage, $passingScore);
            if ($analytics && empty($overallFeedback)) {
                $overallFeedback = (string) ($analytics['summary'] ?? '');
            }
        }

        $passed = $pendingManual === 0 && $percentage >= $passingScore;

        return [
            'question_results' => $results,
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'passed' => $passed,
            'feedback' => $overallFeedback ?: ($pendingManual > 0
                ? 'Submitted. Your instructor will review your oral responses.'
                : ($passed
                    ? 'Well done! You passed this quiz.'
                    : 'Keep studying this topic and try again.')),
            'marking_provider' => $markingProvider,
            'analytics' => $analytics,
            'pending_manual_review' => $pendingManual > 0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $questionResults
     * @param  array<int, array<string, mixed>>  $grades
     * @return array<string, mixed>
     */
    public function applyManualGrades(array $questionResults, array $grades, int $passingScore = 70): array
    {
        $byId = [];
        foreach ($grades as $grade) {
            $qid = (string) ($grade['question_id'] ?? '');
            if ($qid !== '') {
                $byId[$qid] = $grade;
            }
        }

        $score = 0;
        $maxScore = 0;
        $pending = 0;

        $updated = array_map(function (array $row) use ($byId, &$score, &$maxScore, &$pending) {
            $max = (int) ($row['max_score'] ?? 0);
            $maxScore += $max;
            $qid = (string) ($row['question_id'] ?? '');

            if (($row['type'] ?? '') === 'oral_listen' && !empty($row['pending_review']) && isset($byId[$qid])) {
                $grade = $byId[$qid];
                $given = min($max, max(0, (int) ($grade['score'] ?? 0)));
                $row['score'] = $given;
                $row['correct'] = $max > 0 ? $given >= $max : $given > 0;
                $row['feedback'] = trim((string) ($grade['feedback'] ?? '')) ?: 'Marked by instructor.';
                $row['marked_by'] = 'manual';
                $row['pending_review'] = false;
                $score += $given;
            } elseif (!empty($row['pending_review'])) {
                $pending++;
                $score += (int) ($row['score'] ?? 0);
            } else {
                $score += (int) ($row['score'] ?? 0);
            }

            return $row;
        }, $questionResults);

        $percentage = $maxScore > 0 ? round(($score / $maxScore) * 100, 2) : 0;

        return [
            'question_results' => $updated,
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'passed' => $pending === 0 && $percentage >= $passingScore,
            'pending_manual_review' => $pending > 0,
            'feedback' => $pending > 0
                ? 'Partially marked — some oral responses still await review.'
                : ($percentage >= $passingScore ? 'Marked complete — you passed.' : 'Marked complete — review your feedback.'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<int, array<string, mixed>>
     */
    public function stripAnswersForLearner(array $questions): array
    {
        return array_map(function (array $q) {
            $type = (string) ($q['type'] ?? 'multiple_choice');

            unset(
                $q['correct_answer'],
                $q['correct_answers'],
                $q['model_answer'],
                $q['explanation'],
                $q['marking_rubric'],
                $q['confidence_score'],
                $q['source_section'],
                $q['source_paragraph']
            );

            if ($type === 'true_false') {
                $q['options'] = ['True', 'False'];
            }

            if (in_array($type, ['multiple_choice', 'multiple_response'], true) && isset($q['options']) && is_array($q['options'])) {
                $q['options'] = QuizOptionSorter::sort($q['options']);
            }

            if ($type === 'matching' && isset($q['pairs']) && is_array($q['pairs'])) {
                $q['pairs'] = array_values(array_map(
                    fn ($p) => ['left' => (string) ($p['left'] ?? '')],
                    $q['pairs']
                ));
                $rights = array_values(array_map(fn ($p) => (string) ($p['right'] ?? ''), $q['pairs'] ?? []));
                shuffle($rights);
                $q['match_options'] = $rights;
            }

            if ($type === 'fill_blank') {
                unset($q['acceptable_answers']);
            }

            if ($type === 'oral_listen') {
                // Keep prompt audio + response format for learner UI; hide model answer/rubric.
            }

            return $q;
        }, $questions);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function resolveQuestionCount(int $count, array $options): int
    {
        return max(1, min(100, $count));
    }

    protected function resolveGenerationModel(): ?string
    {
        $fast = config('services.quiz_ai.fast_generation_model');
        if (is_string($fast) && trim($fast) !== '') {
            return trim($fast);
        }

        $claude = config('services.quiz_ai.claude_generation_model')
            ?: config('services.anthropic.model', 'claude-sonnet-4-6');

        return is_string($claude) && trim($claude) !== '' ? trim($claude) : null;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveAiProviderOrder(string $context = 'generation'): array
    {
        if (filter_var(config('services.quiz_ai.gemini_only', true), FILTER_VALIDATE_BOOL) && $this->hasGemini()) {
            return ['gemini'];
        }

        if ($context === 'marking') {
            $primary = strtolower((string) config('services.quiz_ai.marking_primary', 'gemini'));
            $secondary = strtolower((string) config('services.quiz_ai.marking_secondary', 'claude'));
        } else {
            $primary = strtolower((string) config('services.quiz_ai.generation_provider', 'gemini'));
            $secondary = $primary === 'gemini' ? 'claude' : 'gemini';
        }

        if ($context === 'generation'
            && filter_var(config('services.quiz_ai.prefer_gemini_for_speed', true), FILTER_VALIDATE_BOOL)) {
            return ['gemini', 'claude'];
        }

        $order = [$primary, $secondary];

        return array_values(array_unique(array_filter($order, fn ($name) => in_array($name, ['claude', 'gemini'], true))));
    }

    /**
     * @param  Collection<int, CourseMaterial>  $materials
     * @param  array<string, mixed>|null  $knowledgeMap
     */
    protected function formatGenerationContext(
        Course $course,
        string $topic,
        Collection $materials,
        string $ragContext,
        ?array $knowledgeMap
    ): string {
        $sourceLines = $materials->map(function (CourseMaterial $material) {
            $meta = QuizMaterialHelper::meta($material);
            $parts = array_filter([
                $material->title,
                !empty($meta['module']) ? 'Module: ' . $meta['module'] : null,
                !empty($meta['chapter']) ? 'Chapter: ' . $meta['chapter'] : null,
            ]);

            return '- ' . implode(' · ', $parts);
        })->implode("\n");

        $mapSection = '';
        if (is_array($knowledgeMap) && $knowledgeMap !== []) {
            $compactMap = array_filter([
                'main_topics' => array_slice($knowledgeMap['main_topics'] ?? [], 0, 8),
                'key_concepts' => array_slice($knowledgeMap['key_concepts'] ?? [], 0, 12),
                'difficulty_level' => $knowledgeMap['difficulty_level'] ?? null,
            ]);
            if ($compactMap !== []) {
                $mapSection = "\n\nKnowledge map (cached analysis):\n" . json_encode($compactMap, JSON_UNESCAPED_UNICODE);
            }
        }

        return implode("\n", array_filter([
            'Course: ' . ($course->title ?? 'Untitled'),
            'Topic focus: ' . $topic,
            'Source material(s):',
            $sourceLines,
            $mapSection,
            '',
            '=== RETRIEVED STUDY MATERIAL (RAG — use ONLY this content) ===',
            $ragContext,
        ]));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function generationPrompt(
        Course $course,
        string $topic,
        int $count,
        string $difficulty,
        string $context,
        array $options = [],
        int $batchNumber = 1,
    ): string {
        $types = array_values(array_filter(
            $options['question_types'] ?? ['multiple_choice', 'true_false'],
            fn ($t) => $t !== 'oral_listen'
        ));
        if (!is_array($types) || $types === []) {
            $types = ['multiple_choice', 'true_false'];
        }
        $types = array_values(array_intersect($types, $this->supportedTypes));
        if ($types === []) {
            $types = ['multiple_choice', 'true_false'];
        }

        $bloom = $options['bloom_levels'] ?? ['remember', 'understand', 'apply', 'analyze'];
        if (!is_array($bloom) || $bloom === []) {
            $bloom = ['remember', 'understand', 'apply', 'analyze'];
        }
        $bloomList = implode(', ', $bloom);
        $typeList = implode(', ', $types);
        $difficulty = in_array($difficulty, ['easy', 'medium', 'hard', 'mixed'], true) ? $difficulty : 'medium';
        $batchNote = $batchNumber > 1 ? "\nBatch {$batchNumber}: generate different questions from earlier batches." : '';
        $langInstruction = (string) ($options['language_instruction']
            ?? MaterialLanguageHelper::promptInstruction((string) ($options['assessment_language'] ?? 'en')));

        return <<<PROMPT
{$langInstruction}

Generate exactly {$count} quiz questions (difficulty: {$difficulty}, topic: "{$topic}").{$batchNote}

Use ONLY the study material below. If insufficient facts, return {"insufficient": true, "questions": []}.

Types: {$typeList} | Bloom: {$bloomList}

{$context}

Return JSON only:
{"questions":[{"id":"q1","question":"...","type":"multiple_choice","difficulty":"medium","bloom_level":"understand","source_section":"...","options":["A","B","C","D"],"correct_answer":"B","explanation":"...","confidence_score":0.9,"points":1}]}

Rules: ids q1..q{$count}; multiple_choice needs 4 options; true_false answer is True or False; cite source_section from material.
PROMPT;
    }

    /**
     * @return Collection<int, CourseMaterial>
     */
    protected function resolveContextMaterials(Course $course, string $topic, ?int $materialId = null): Collection
    {
        $studyMaterials = CourseMaterial::query()
            ->where('course_id', $course->id)
            ->whereNotIn('type', ['quiz', 'assessment', 'zoom'])
            ->orderBy('sort_order')
            ->get();

        if ($materialId) {
            $selected = $studyMaterials->firstWhere('id', $materialId);
            if (!$selected) {
                throw new \RuntimeException('Selected material does not belong to this course.');
            }

            return collect([$selected]);
        }

        $topicMaterials = QuizMaterialHelper::materialsForTopic($studyMaterials, $topic);
        if ($topicMaterials !== []) {
            return collect($topicMaterials);
        }

        $pdfs = $studyMaterials->filter(fn (CourseMaterial $m) => QuizMaterialHelper::isPdfMaterial($m));
        if ($pdfs->count() === 1) {
            return collect([$pdfs->first()]);
        }

        return $studyMaterials->take(3);
    }

    protected function markExact(array $question, mixed $studentAnswer, int $points, string $qid): array
    {
        $correct = QuizAnswerMatcher::resolveCorrectText($question);
        $student = QuizAnswerMatcher::normalize((string) $studentAnswer);
        $isCorrect = QuizAnswerMatcher::matchesExact($question, $studentAnswer);

        return [
            'question_id' => $qid,
            'type' => $question['type'] ?? 'multiple_choice',
            'correct' => $isCorrect,
            'score' => $isCorrect ? $points : 0,
            'max_score' => $points,
            'student_answer' => $student !== '' ? $student : $studentAnswer,
            'correct_answer' => $correct,
            'explanation' => $question['explanation'] ?? null,
            'marked_by' => 'auto',
        ];
    }

    protected function markMultipleResponse(array $question, mixed $studentAnswer, int $points, string $qid): array
    {
        $correctRaw = $question['correct_answers'] ?? [$question['correct_answer'] ?? ''];
        if (!is_array($correctRaw)) {
            $correctRaw = [$correctRaw];
        }
        $correct = array_values(array_filter(array_map(
            fn ($answer) => QuizAnswerMatcher::resolveCorrectText($question, (string) $answer),
            $correctRaw
        )));
        $student = is_array($studentAnswer)
            ? array_map(fn ($answer) => QuizAnswerMatcher::resolveCorrectText($question, (string) $answer), $studentAnswer)
            : array_filter(array_map(
                fn ($part) => QuizAnswerMatcher::resolveCorrectText($question, $part),
                explode(',', (string) $studentAnswer)
            ));

        sort($correct);
        sort($student);
        $isCorrect = $correct === $student;

        return [
            'question_id' => $qid,
            'type' => 'multiple_response',
            'correct' => $isCorrect,
            'score' => $isCorrect ? $points : 0,
            'max_score' => $points,
            'student_answer' => $studentAnswer,
            'correct_answer' => implode(', ', $correct),
            'marked_by' => 'auto',
        ];
    }

    protected function markMatching(array $question, mixed $studentAnswer, int $points, string $qid): array
    {
        $pairs = $question['pairs'] ?? [];
        $studentPairs = is_array($studentAnswer) ? $studentAnswer : json_decode((string) $studentAnswer, true);
        if (!is_array($studentPairs)) {
            $studentPairs = [];
        }

        $total = max(1, count($pairs));
        $correctCount = 0;
        foreach ($pairs as $pair) {
            $left = (string) ($pair['left'] ?? '');
            $right = (string) ($pair['right'] ?? '');
            if ($left !== '' && (($studentPairs[$left] ?? null) === $right)) {
                $correctCount++;
            }
        }

        $ratio = $correctCount / $total;
        $earned = (int) round($points * $ratio);

        return [
            'question_id' => $qid,
            'type' => 'matching',
            'correct' => $ratio >= 1,
            'score' => $earned,
            'max_score' => $points,
            'student_answer' => $studentAnswer,
            'marked_by' => 'auto',
        ];
    }

    protected function markFillBlank(array $question, mixed $studentAnswer, int $points, int|string $qid): array
    {
        $acceptable = $question['acceptable_answers'] ?? [$question['correct_answer'] ?? ''];
        if (!is_array($acceptable)) {
            $acceptable = [$acceptable];
        }

        $student = strtolower(trim((string) $studentAnswer));
        $isCorrect = false;
        foreach ($acceptable as $answer) {
            $answer = strtolower(trim((string) $answer));
            if ($answer === '' || $student === '') {
                continue;
            }
            if ($student === $answer || similar_text($student, $answer) / max(strlen($answer), 1) > 0.82) {
                $isCorrect = true;
                break;
            }
        }

        return [
            'question_id' => (string) $qid,
            'type' => 'fill_blank',
            'correct' => $isCorrect,
            'score' => $isCorrect ? $points : 0,
            'max_score' => $points,
            'student_answer' => $studentAnswer,
            'marked_by' => 'auto',
        ];
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array{text: string, original: string, transcription?: string}|null
     */
    protected function prepareOralAnswer(array $question, mixed $studentAnswer): ?array
    {
        $raw = trim((string) $studentAnswer);
        if ($raw === '') {
            return null;
        }

        $responseFormat = (string) ($question['response_format'] ?? 'text');

        if (str_starts_with($raw, 'audio:')) {
            $inner = substr($raw, 6);
            $transcription = $this->transcribeStoredAudio($inner);
            if ($transcription === null || trim($transcription) === '') {
                return [
                    'text' => '[Audio answer — transcription unavailable]',
                    'original' => $raw,
                ];
            }

            return [
                'text' => $transcription,
                'original' => $raw,
                'transcription' => $transcription,
            ];
        }

        if ($responseFormat === 'audio') {
            return null;
        }

        return [
            'text' => $raw,
            'original' => $raw,
        ];
    }

    public function transcribeStoredAudio(string $storagePath): ?string
    {
        $parsed = \App\Support\QuizAudioHelper::parseRef($storagePath);
        if ($parsed === null) {
            $parsed = \App\Support\QuizAudioHelper::parseRef('audio:' . ltrim($storagePath, '/'));
        }

        if (($parsed['type'] ?? '') === 'pcloud') {
            $fileId = (int) ($parsed['file_id'] ?? 0);
            $pcloud = app(\App\Services\PCloudService::class);
            if ($fileId <= 0 || !$pcloud->isConfigured()) {
                return null;
            }

            try {
                $url = $pcloud->downloadLink($fileId);
                $response = \Illuminate\Support\Facades\Http::timeout(120)->get($url);
                if (!$response->successful()) {
                    return null;
                }
                $bytes = $response->body();
                $filename = 'answer-' . $fileId . '.webm';

                return $this->documentReader->transcribeMediaBytes($bytes, $filename);
            } catch (\Throwable) {
                return null;
            }
        }

        $path = (string) ($parsed['path'] ?? ltrim(str_replace(['\\', '..'], ['/', ''], $storagePath), '/'));
        if ($path === '' || !str_starts_with($path, 'uploads/quiz-audio/')) {
            return null;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        if (!$disk->exists($path)) {
            return null;
        }

        $bytes = $disk->get($path);
        $filename = basename($path);

        return $this->documentReader->transcribeMediaBytes($bytes, $filename);
    }

    protected function markOpenAnswersWithAi(array $openItems, ?string $languageCode = null): array
    {
        $payload = json_encode(['items' => $openItems], JSON_UNESCAPED_UNICODE);
        $langInstruction = MaterialLanguageHelper::markingFeedbackInstruction($languageCode ?: 'en');
        $prompt = <<<PROMPT
Grade these open-ended quiz answers using ONLY the model answers/rubrics provided.
For oral_listen items, evaluate comprehension/summary quality against the model answer or rubric.

{$langInstruction}

Essay rubric weights:
- Content accuracy 30%
- Understanding 25%
- Critical thinking 20%
- Structure 15%
- Language 10%

Return JSON only:
{
  "overall_feedback": "...",
  "results": [
    {
      "question_id": "q2",
      "type": "short_answer",
      "correct": true,
      "score": 2,
      "max_score": 2,
      "student_answer": "...",
      "feedback": "...",
      "improvement_suggestions": "..."
    }
  ]
}

Items: {$payload}
PROMPT;

        $raw = null;
        $provider = 'gemini';

        foreach ($this->resolveAiProviderOrder('marking') as $name) {
            if ($name === 'claude' && $this->hasClaude()) {
                $raw = $this->callClaude($prompt, 2048);
                $provider = 'claude';
            } elseif ($name === 'gemini' && $this->hasGemini()) {
                $raw = $this->callGemini($prompt, 2048, true);
                $provider = 'gemini';
            }

            if ($raw !== null) {
                break;
            }
        }

        if ($raw === null) {
            return $this->fallbackMarkOpen($openItems);
        }

        try {
            $parsed = $this->parseQuestionsJson($raw);
            $results = $parsed['results'] ?? [];
            foreach ($results as &$row) {
                $row['marked_by'] = $provider;
            }

            return [
                'provider' => $provider,
                'overall_feedback' => (string) ($parsed['overall_feedback'] ?? ''),
                'results' => $results,
            ];
        } catch (\Throwable $e) {
            return $this->fallbackMarkOpen($openItems);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>|null
     */
    protected function buildPersonalizedFeedback(array $results, float $percentage, int $passingScore): ?array
    {
        $summary = json_encode([
            'percentage' => $percentage,
            'passing_score' => $passingScore,
            'results' => array_map(fn ($r) => [
                'question_id' => $r['question_id'] ?? '',
                'correct' => $r['correct'] ?? false,
                'type' => $r['type'] ?? '',
            ], $results),
        ], JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Based on these quiz results, return JSON only:
{
  "summary": "2-3 sentence personalized feedback",
  "strengths": ["..."],
  "weaknesses": ["..."],
  "learning_gaps": ["..."],
  "recommendations": ["..."]
}
Data: {$summary}
PROMPT;

        $raw = null;
        foreach ($this->resolveAiProviderOrder('marking') as $name) {
            if ($name === 'claude' && $this->hasClaude()) {
                $raw = $this->callClaude($prompt, 800);
            } elseif ($name === 'gemini' && $this->hasGemini()) {
                $raw = $this->callGemini($prompt, 800, true);
            }
            if ($raw !== null) {
                break;
            }
        }

        if ($raw === null) {
            return null;
        }

        try {
            return $this->parseQuestionsJson($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function fallbackMarkOpen(array $openItems): array
    {
        $results = [];
        foreach ($openItems as $item) {
            $student = trim((string) ($item['student_answer'] ?? ''));
            $model = trim((string) ($item['model_answer'] ?? ''));
            $points = (int) ($item['points'] ?? 1);
            $similar = $student !== '' && $model !== ''
                && similar_text(strtolower($student), strtolower($model)) / max(strlen($model), 1) > 0.55;
            $earned = $similar ? $points : (strlen($student) > 20 ? (int) ceil($points / 2) : 0);

            $results[] = [
                'question_id' => $item['question_id'],
                'type' => $item['type'],
                'correct' => $earned >= $points,
                'score' => $earned,
                'max_score' => $points,
                'student_answer' => $student,
                'feedback' => 'Graded with similarity matching (AI unavailable).',
                'marked_by' => 'fallback',
            ];
        }

        return [
            'provider' => 'fallback',
            'overall_feedback' => 'Your answers were reviewed. AI marking was unavailable for some items.',
            'results' => $results,
        ];
    }

    protected function callClaude(string $prompt, int $maxTokens = 2048, ?string $model = null): ?string
    {
        $key = config('services.anthropic.api_key');
        if (!$key) {
            return null;
        }

        $model = $model ?: config('services.anthropic.model', 'claude-sonnet-4-6');

        try {
            $response = Http::timeout(90)
                ->connectTimeout(15)
                ->withHeaders([
                    'x-api-key' => $key,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'temperature' => 0.25,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (!$response->successful()) {
                $this->lastAiError = $this->summarizeHttpError('Claude', $response->status(), $response->body());
                Log::warning('Claude API error', ['body' => $response->body()]);

                return null;
            }

            foreach ($response->json('content') ?? [] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    return (string) ($block['text'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            $this->lastAiError = 'Claude request failed: ' . $e->getMessage();
            Log::error('Claude request failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    protected function callGemini(string $prompt, int $maxTokens = 2048, bool $jsonMode = false): ?string
    {
        $keys = $this->geminiApiKeys();
        if ($keys === []) {
            return null;
        }

        $model = config('services.quiz_ai.generation_model')
            ?: config('services.gemini.model', 'gemini-2.5-flash');

        $generationConfig = $this->buildGeminiGenerationConfig($maxTokens, $jsonMode);

        $payload = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => $generationConfig,
        ];

        foreach ($keys as $keyLabel => $key) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

            try {
                $response = Http::timeout(75)->connectTimeout(12)->post($url, $payload);

                if (!$response->successful()) {
                    $this->lastAiError = $this->summarizeHttpError("Gemini ({$keyLabel})", $response->status(), $response->body());
                    continue;
                }

                $responseJson = $response->json();
                $finishReason = (string) data_get($responseJson, 'candidates.0.finishReason', '');
                if ($finishReason === 'MAX_TOKENS') {
                    $this->lastAiError = 'Gemini output was truncated (MAX_TOKENS). Try fewer questions per batch.';
                }

                $text = $this->extractGeminiText(is_array($responseJson) ? $responseJson : null);
                if ($text !== '') {
                    return $text;
                }
            } catch (\Throwable $e) {
                $this->lastAiError = "Gemini ({$keyLabel}) request failed: " . $e->getMessage();
            }
        }

        return null;
    }

    protected function generationMaxTokens(int $questionCount): int
    {
        return min(8192, max(1800, ($questionCount * 380) + 600));
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildGeminiGenerationConfig(int $maxTokens, bool $jsonMode = false): array
    {
        $config = [
            'temperature' => 0.2,
            'maxOutputTokens' => $maxTokens,
            // Gemini 2.5 "thinking" tokens consume the output budget and truncate JSON — disable for quiz generation.
            'thinkingConfig' => ['thinkingBudget' => 0],
        ];

        if ($jsonMode) {
            $config['responseMimeType'] = 'application/json';
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>|null  $responseJson
     */
    protected function extractGeminiText(?array $responseJson): string
    {
        if (!is_array($responseJson)) {
            return '';
        }

        $parts = data_get($responseJson, 'candidates.0.content.parts', []);
        if (!is_array($parts)) {
            return '';
        }

        $texts = [];
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (!empty($part['thought'])) {
                continue;
            }
            $text = trim((string) ($part['text'] ?? ''));
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return trim(implode("\n", $texts));
    }

    /** @return array<string, string> */
    protected function geminiApiKeys(): array
    {
        $keys = [];
        foreach (['GOOGLE_AI_API_KEY', 'GEMINI_API_KEY'] as $name) {
            $value = env($name);
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if ($value === '' || isset($keys[$value])) {
                continue;
            }
            $keys[$name] = $value;
        }

        return $keys;
    }

    protected function summarizeHttpError(string $provider, int $status, string $body): string
    {
        $message = null;
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $message = data_get($decoded, 'error.message') ?: data_get($decoded, 'error.error.message');
        }

        return trim($provider . ' HTTP ' . $status . ($message ? ': ' . $message : ''));
    }

    /** @return array<string, mixed> */
    protected function parseQuestionsJson(string $raw): array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```\s*$/', '', $raw) ?? $raw;

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $raw = substr($raw, $start, $end - $start + 1);
        }

        $decoded = $this->decodeJsonLenient($raw);
        if (is_array($decoded)) {
            return $decoded;
        }

        $repaired = $this->repairTruncatedQuestionsJson($raw);
        if ($repaired !== null) {
            return $repaired;
        }

        Log::warning('Quiz AI JSON parse failed', ['snippet' => substr($raw, 0, 400)]);

        throw new \RuntimeException('AI returned invalid JSON. Try fewer questions or regenerate.');
    }

    protected function decodeJsonLenient(string $raw): ?array
    {
        $attempts = [
            $raw,
            preg_replace('/,\s*([}\]])/', '$1', $raw) ?? $raw,
            preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $raw) ?? $raw,
        ];

        foreach ($attempts as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function repairTruncatedQuestionsJson(string $raw): ?array
    {
        if (!str_contains($raw, '"questions"')) {
            return null;
        }

        $attempt = rtrim($raw);
        for ($i = 0; $i < 6; $i++) {
            $attempt = preg_replace('/,\s*"[^"]*$/', '', $attempt) ?? $attempt;
            $attempt = preg_replace('/,\s*$/', '', $attempt) ?? $attempt;

            if (!str_ends_with($attempt, ']')) {
                $attempt .= ']';
            }
            if (!str_ends_with($attempt, '}')) {
                $attempt .= '}';
            }

            $decoded = $this->decodeJsonLenient($attempt);
            if (is_array($decoded) && !empty($decoded['questions']) && is_array($decoded['questions'])) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  mixed  $questions
     * @param  array<string, mixed>  $options
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeQuestions($questions, array $options = []): array
    {
        if (!is_array($questions)) {
            throw new \RuntimeException('No questions returned from AI.');
        }

        $allowedTypes = $options['question_types'] ?? $this->supportedTypes;
        if (!is_array($allowedTypes) || $allowedTypes === []) {
            $allowedTypes = ['multiple_choice', 'true_false'];
        }

        $normalized = [];
        $i = 1;
        foreach ($questions as $q) {
            if (!is_array($q)) {
                continue;
            }

            $type = in_array($q['type'] ?? '', $this->supportedTypes, true) ? $q['type'] : 'multiple_choice';
            if (!in_array($type, $allowedTypes, true)) {
                $type = in_array('multiple_choice', $allowedTypes, true) ? 'multiple_choice' : ($allowedTypes[0] ?? 'multiple_choice');
            }

            $optionsList = array_values($q['options'] ?? []);
            if ($type === 'true_false') {
                $optionsList = ['True', 'False'];
            } elseif (in_array($type, ['multiple_choice', 'multiple_response'], true) && count($optionsList) >= 2) {
                $optionsList = QuizOptionSorter::sort($optionsList);
            }

            $normalizedQuestion = array_filter([
                'id' => (string) ($q['id'] ?? ('q' . $i)),
                'type' => $type,
                'question' => (string) ($q['question'] ?? 'Question'),
                'options' => $optionsList ?: null,
                'correct_answer' => isset($q['correct_answer']) ? (string) $q['correct_answer'] : null,
                'correct_answers' => isset($q['correct_answers']) && is_array($q['correct_answers']) ? array_values($q['correct_answers']) : null,
                'acceptable_answers' => isset($q['acceptable_answers']) && is_array($q['acceptable_answers']) ? array_values($q['acceptable_answers']) : null,
                'pairs' => isset($q['pairs']) && is_array($q['pairs']) ? $q['pairs'] : null,
                'model_answer' => isset($q['model_answer']) ? (string) $q['model_answer'] : null,
                'marking_rubric' => isset($q['marking_rubric']) ? (string) $q['marking_rubric'] : null,
                'explanation' => isset($q['explanation']) ? (string) $q['explanation'] : null,
                'difficulty' => (string) ($q['difficulty'] ?? 'medium'),
                'bloom_level' => (string) ($q['bloom_level'] ?? 'understand'),
                'source_section' => (string) ($q['source_section'] ?? ''),
                'source_paragraph' => (string) ($q['source_paragraph'] ?? ''),
                'confidence_score' => (float) ($q['confidence_score'] ?? 0.85),
                'estimated_time' => (int) ($q['estimated_time'] ?? 60),
                'points' => max(1, (int) ($q['points'] ?? 1)),
            ], fn ($v) => $v !== null && $v !== '');
            $normalized[] = QuizAnswerMatcher::normalizeQuestionAnswers($normalizedQuestion);
            $i++;
        }

        if ($normalized === []) {
            throw new \RuntimeException('AI did not return usable questions.');
        }

        return $normalized;
    }
}
