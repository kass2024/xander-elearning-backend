<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAttempt extends Model
{
    protected $fillable = [
        'student_id',
        'course_material_id',
        'answers',
        'question_results',
        'score',
        'max_score',
        'percentage',
        'passed',
        'feedback',
        'marking_provider',
        'tab_switch_count',
        'focus_lost_seconds',
        'integrity_flags',
        'delivered_question_ids',
        'marked_at',
    ];

    protected $casts = [
        'answers' => 'array',
        'question_results' => 'array',
        'integrity_flags' => 'array',
        'delivered_question_ids' => 'array',
        'passed' => 'boolean',
        'marked_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(CourseMaterial::class, 'course_material_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toLearnerSummary(): array
    {
        $results = is_array($this->question_results) ? $this->question_results : [];
        $pendingReview = $this->marked_at === null && (
            collect($results)->contains(fn ($row) => !empty($row['pending_review']))
            || ($this->marking_provider === 'manual' && $this->marked_at === null && $this->created_at !== null)
        );

        return [
            'id' => $this->id,
            'score' => (int) $this->score,
            'max_score' => (int) $this->max_score,
            'percentage' => round((float) $this->percentage, 1),
            'passed' => (bool) $this->passed,
            'feedback' => (string) ($this->feedback ?? ''),
            'marking_provider' => (string) ($this->marking_provider ?? ''),
            'pending_review' => $pendingReview,
            'marked_at' => $this->marked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'question_results' => $results,
        ];
    }
}
