<?php

namespace App\Services\Quiz;

use App\Models\CourseMaterial;
use App\Models\QuizAttempt;
use App\Models\Student;
use App\Support\QuizMaterialHelper;

class QuizMarkingGuideService
{
    /**
     * @return array<string, mixed>
     */
    public function buildPayload(CourseMaterial $quiz, QuizAttempt $attempt, string $audience = 'learner'): array
    {
        $meta = QuizMaterialHelper::meta($quiz);
        $questionsById = collect($meta['questions'] ?? [])->keyBy(fn ($q) => (string) ($q['id'] ?? ''));
        $passingScore = (int) ($meta['passing_score'] ?? 70);
        $student = $attempt->student;
        $results = is_array($attempt->question_results) ? $attempt->question_results : [];

        $rows = [];
        foreach ($results as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $qid = (string) ($row['question_id'] ?? '');
            $question = $questionsById->get($qid, []);
            $type = (string) ($row['type'] ?? ($question['type'] ?? 'question'));

            $rows[] = [
                'number' => $index + 1,
                'question_id' => $qid,
                'type' => $type,
                'question' => (string) ($question['question'] ?? $question['instruction'] ?? ('Question ' . ($index + 1))),
                'student_answer' => $this->formatAnswer($row['student_answer'] ?? ''),
                'correct_answer' => $this->formatAnswer($row['correct_answer'] ?? ($question['correct_answer'] ?? '')),
                'explanation' => (string) ($row['explanation'] ?? $question['explanation'] ?? ''),
                'score' => (int) ($row['score'] ?? 0),
                'max_score' => (int) ($row['max_score'] ?? 1),
                'correct' => $row['correct'] ?? null,
                'feedback' => (string) ($row['feedback'] ?? ''),
                'marked_by' => (string) ($row['marked_by'] ?? 'auto'),
                'pending_review' => !empty($row['pending_review']),
            ];
        }

        $percentage = round((float) $attempt->percentage, 1);
        $passed = (bool) $attempt->passed;

        return [
            'audience' => $audience,
            'quiz_title' => (string) ($quiz->title ?? 'Quiz'),
            'topic' => (string) ($meta['topic'] ?? ''),
            'student_name' => $student instanceof Student
                ? (string) ($student->name ?? $student->email ?? ('Student #' . $attempt->student_id))
                : ('Student #' . $attempt->student_id),
            'attempt_id' => $attempt->id,
            'submitted_at' => $attempt->created_at?->format('Y-m-d H:i') ?? '',
            'marked_at' => $attempt->marked_at?->format('Y-m-d H:i') ?? '',
            'score' => (int) $attempt->score,
            'max_score' => (int) $attempt->max_score,
            'percentage' => $percentage,
            'passing_score' => $passingScore,
            'passed' => $passed,
            'pass_label' => $passed ? 'PASSED' : 'NOT PASSED',
            'marking_provider' => (string) ($attempt->marking_provider ?? 'auto'),
            'feedback' => (string) ($attempt->feedback ?? ''),
            'rows' => $rows,
            'generated_at' => now()->format('Y-m-d H:i'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function renderHtml(array $payload): string
    {
        return view('quiz.marking-guide', $payload)->render();
    }

    protected function formatAnswer(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn ($item) => trim((string) $item), $value));
        }

        $text = trim((string) $value);
        if ($text === '') {
            return '—';
        }

        if (str_starts_with($text, 'audio:')) {
            return '[Audio answer recorded]';
        }

        return $text;
    }
}
