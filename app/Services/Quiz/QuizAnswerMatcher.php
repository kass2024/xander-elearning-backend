<?php

namespace App\Services\Quiz;

class QuizAnswerMatcher
{
    public static function normalize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value) ?? $value;

        return $value;
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public static function lookupAnswer(array $answers, string $questionId): mixed
    {
        if (array_key_exists($questionId, $answers)) {
            return $answers[$questionId];
        }

        foreach ($answers as $key => $value) {
            if ((string) $key === $questionId) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Resolve MCQ/TF stored correct answer to the option text shown to the learner.
     *
     * @param  array<string, mixed>  $question
     */
    public static function resolveCorrectText(array $question, ?string $rawCorrect = null): string
    {
        $raw = self::normalize((string) ($rawCorrect ?? $question['correct_answer'] ?? ''));
        if ($raw === '') {
            return '';
        }

        $options = array_values(array_map(
            fn ($option) => self::normalize((string) $option),
            is_array($question['options'] ?? null) ? $question['options'] : []
        ));

        if ($options === []) {
            return $raw;
        }

        if (preg_match('/^[A-D]$/i', $raw)) {
            $index = ord(strtoupper($raw)) - ord('A');
            if (isset($options[$index])) {
                return $options[$index];
            }
        }

        if (preg_match('/^([A-D])[\).:\-]\s*(.*)$/iu', $raw, $matches)) {
            $index = ord(strtoupper($matches[1])) - ord('A');
            if (isset($options[$index])) {
                return $options[$index];
            }
            if (trim($matches[2]) !== '') {
                return self::normalize($matches[2]);
            }
        }

        foreach ($options as $option) {
            if (strcasecmp($option, $raw) === 0) {
                return $option;
            }
        }

        foreach ($options as $option) {
            if (stripos($option, $raw) === 0 || stripos($raw, $option) === 0) {
                return $option;
            }
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $question
     */
    public static function matchesExact(array $question, mixed $studentAnswer, ?string $rawCorrect = null): bool
    {
        $student = self::normalize((string) $studentAnswer);
        if ($student === '') {
            return false;
        }

        $correct = self::resolveCorrectText($question, $rawCorrect);
        if ($correct === '') {
            return false;
        }

        if (strcasecmp($student, $correct) === 0) {
            return true;
        }

        $resolvedStudent = self::resolveCorrectText($question, $student);
        if (strcasecmp($resolvedStudent, $correct) === 0) {
            return true;
        }

        $options = is_array($question['options'] ?? null) ? $question['options'] : [];
        foreach ($options as $option) {
            $normalizedOption = self::normalize((string) $option);
            if (strcasecmp($student, $normalizedOption) === 0 && strcasecmp($normalizedOption, $correct) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize MCQ correct_answer to full option text when saving questions.
     *
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    public static function normalizeQuestionAnswers(array $question): array
    {
        $type = (string) ($question['type'] ?? '');
        if (!in_array($type, ['multiple_choice', 'true_false'], true)) {
            return $question;
        }

        if (!empty($question['correct_answer'])) {
            $question['correct_answer'] = self::resolveCorrectText($question, (string) $question['correct_answer']);
        }

        return $question;
    }
}
