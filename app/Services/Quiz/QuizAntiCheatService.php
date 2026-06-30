<?php

namespace App\Services\Quiz;

class QuizAntiCheatService
{
    /**
     * @param  array<string, mixed>  $meta
     * @return array{questions: array<int, array<string, mixed>>, delivered_ids: array<int, string>}
     */
    public function prepareDelivery(array $meta, int $studentId): array
    {
        $settings = is_array($meta['anti_cheat'] ?? null) ? $meta['anti_cheat'] : [];
        $deliverCount = (int) ($settings['deliver_count'] ?? 0);
        $questions = is_array($meta['questions'] ?? null) ? $meta['questions'] : [];
        $poolMeta = is_array($meta['question_pool'] ?? null) ? $meta['question_pool'] : [];

        // Use question_pool only when subset delivery is configured; otherwise always use questions
        // (stale question_pool copies were missing oral prompt_audio_url after edits).
        if ($deliverCount > 0 && $poolMeta !== []) {
            $pool = $this->mergeQuestionPoolWithCanonical($poolMeta, $questions);
        } else {
            $pool = $questions;
        }

        if ($deliverCount > 0 && count($pool) > $deliverCount) {
            $pool = $this->deterministicSample($pool, $deliverCount, $studentId, (int) ($meta['source_material_id'] ?? 0));
        }

        if ($settings['shuffle_questions'] ?? true) {
            $pool = $this->deterministicShuffle($pool, $studentId, 'questions');
        }

        $prepared = [];
        foreach ($pool as $question) {
            if (!is_array($question)) {
                continue;
            }
            $prepared[] = $this->prepareOptionsOrder($question);
        }

        $ids = array_values(array_map(fn ($q) => (string) ($q['id'] ?? ''), $prepared));

        return ['questions' => $prepared, 'delivered_ids' => $ids];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function maxAttemptsReached(array $meta, int $studentId, int $courseMaterialId, int $attemptCount): bool
    {
        $settings = is_array($meta['anti_cheat'] ?? null) ? $meta['anti_cheat'] : [];
        $max = (int) ($settings['max_attempts'] ?? 0);

        return $max > 0 && $attemptCount >= $max;
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    protected function prepareOptionsOrder(array $question): array
    {
        $type = (string) ($question['type'] ?? '');
        if (!in_array($type, ['multiple_choice', 'multiple_response'], true)) {
            return $question;
        }

        $options = array_values($question['options'] ?? []);
        if (count($options) < 2) {
            return $question;
        }

        $question['options'] = QuizOptionSorter::sort($options);

        return $question;
    }

    /**
     * @template T
     * @param  array<int, T>  $items
     * @return array<int, T>
     */
    protected function deterministicShuffle(array $items, int $studentId, string $salt): array
    {
        $items = array_values($items);
        usort($items, function ($a, $b) use ($studentId, $salt) {
            $ka = crc32($studentId . ':' . $salt . ':' . json_encode($a));
            $kb = crc32($studentId . ':' . $salt . ':' . json_encode($b));

            return $ka <=> $kb;
        });

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function deterministicSample(array $items, int $count, int $studentId, int $quizId): array
    {
        $items = array_values($items);
        usort($items, function ($a, $b) use ($studentId, $quizId) {
            $ka = crc32($studentId . ':' . $quizId . ':' . ($a['id'] ?? ''));
            $kb = crc32($studentId . ':' . $quizId . ':' . ($b['id'] ?? ''));

            return $ka <=> $kb;
        });

        return array_slice($items, 0, $count);
    }

    /**
     * Merge canonical question fields (e.g. oral audio) into pool copies by id.
     *
     * @param  array<int, array<string, mixed>>  $pool
     * @param  array<int, array<string, mixed>>  $canonical
     * @return array<int, array<string, mixed>>
     */
    protected function mergeQuestionPoolWithCanonical(array $pool, array $canonical): array
    {
        $byId = [];
        foreach ($canonical as $q) {
            if (!is_array($q)) {
                continue;
            }
            $id = (string) ($q['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $q;
            }
        }

        return array_values(array_map(function ($q) use ($byId) {
            if (!is_array($q)) {
                return $q;
            }
            $id = (string) ($q['id'] ?? '');
            if ($id === '' || !isset($byId[$id])) {
                return $q;
            }

            return array_merge($q, array_filter([
                'prompt_audio_url' => $byId[$id]['prompt_audio_url'] ?? null,
                'prompt_audio_filename' => $byId[$id]['prompt_audio_filename'] ?? null,
                'instruction' => $byId[$id]['instruction'] ?? null,
                'response_format' => $byId[$id]['response_format'] ?? null,
                'question' => $byId[$id]['question'] ?? null,
                'points' => $byId[$id]['points'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''));
        }, $pool));
    }
}
