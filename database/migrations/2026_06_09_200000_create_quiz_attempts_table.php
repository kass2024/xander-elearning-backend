<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quiz_attempts')) {
            $this->syncExistingQuizAttemptsTable();

            return;
        }

        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('course_material_id')->constrained('course_materials')->cascadeOnDelete();
            $table->json('answers');
            $table->json('question_results')->nullable();
            $table->unsignedSmallInteger('score')->default(0);
            $table->unsignedSmallInteger('max_score')->default(0);
            $table->decimal('percentage', 5, 2)->default(0);
            $table->boolean('passed')->default(false);
            $table->text('feedback')->nullable();
            $table->string('marking_provider')->nullable();
            $table->unsignedSmallInteger('tab_switch_count')->default(0);
            $table->unsignedInteger('focus_lost_seconds')->default(0);
            $table->json('integrity_flags')->nullable();
            $table->json('delivered_question_ids')->nullable();
            $table->timestamp('marked_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'course_material_id']);
        });
    }

    private function syncExistingQuizAttemptsTable(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $columns = [
                'answers' => fn () => $table->json('answers')->nullable(),
                'question_results' => fn () => $table->json('question_results')->nullable(),
                'score' => fn () => $table->unsignedSmallInteger('score')->default(0),
                'max_score' => fn () => $table->unsignedSmallInteger('max_score')->default(0),
                'percentage' => fn () => $table->decimal('percentage', 5, 2)->default(0),
                'passed' => fn () => $table->boolean('passed')->default(false),
                'feedback' => fn () => $table->text('feedback')->nullable(),
                'marking_provider' => fn () => $table->string('marking_provider')->nullable(),
                'tab_switch_count' => fn () => $table->unsignedSmallInteger('tab_switch_count')->default(0),
                'focus_lost_seconds' => fn () => $table->unsignedInteger('focus_lost_seconds')->default(0),
                'integrity_flags' => fn () => $table->json('integrity_flags')->nullable(),
                'delivered_question_ids' => fn () => $table->json('delivered_question_ids')->nullable(),
                'marked_at' => fn () => $table->timestamp('marked_at')->nullable(),
            ];

            foreach ($columns as $name => $add) {
                if (!Schema::hasColumn('quiz_attempts', $name)) {
                    $add();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
    }
};
