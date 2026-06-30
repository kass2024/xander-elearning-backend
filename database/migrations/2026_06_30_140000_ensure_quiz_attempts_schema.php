<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent quiz_attempts schema for oral assessments & AI marking.
 * Safe on fresh installs and legacy DBs where quiz_attempts existed without all columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('quiz_attempts')) {
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

            return;
        }

        Schema::table('quiz_attempts', function (Blueprint $table) {
            if (!Schema::hasColumn('quiz_attempts', 'answers')) {
                $table->json('answers')->nullable();
            }
            if (!Schema::hasColumn('quiz_attempts', 'question_results')) {
                $table->json('question_results')->nullable();
            }
            if (!Schema::hasColumn('quiz_attempts', 'score')) {
                $table->unsignedSmallInteger('score')->default(0);
            }
            if (!Schema::hasColumn('quiz_attempts', 'max_score')) {
                $table->unsignedSmallInteger('max_score')->default(0);
            }
            if (!Schema::hasColumn('quiz_attempts', 'percentage')) {
                $table->decimal('percentage', 5, 2)->default(0);
            }
            if (!Schema::hasColumn('quiz_attempts', 'passed')) {
                $table->boolean('passed')->default(false);
            }
            if (!Schema::hasColumn('quiz_attempts', 'feedback')) {
                $table->text('feedback')->nullable();
            }
            if (!Schema::hasColumn('quiz_attempts', 'marking_provider')) {
                $table->string('marking_provider')->nullable();
            }
            if (!Schema::hasColumn('quiz_attempts', 'tab_switch_count')) {
                $table->unsignedSmallInteger('tab_switch_count')->default(0);
            }
            if (!Schema::hasColumn('quiz_attempts', 'focus_lost_seconds')) {
                $table->unsignedInteger('focus_lost_seconds')->default(0);
            }
            if (!Schema::hasColumn('quiz_attempts', 'integrity_flags')) {
                $table->json('integrity_flags')->nullable();
            }
            if (!Schema::hasColumn('quiz_attempts', 'delivered_question_ids')) {
                $table->json('delivered_question_ids')->nullable();
            }
            if (!Schema::hasColumn('quiz_attempts', 'marked_at')) {
                $table->timestamp('marked_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Non-destructive: columns may be required by production data.
    }
};
