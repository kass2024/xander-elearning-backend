<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('study_shift_change_requests')) {
            Schema::create('study_shift_change_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_enrollment_id');
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('course_id');
                $table->json('current_study_shift_ids')->nullable();
                $table->json('requested_study_shift_ids');
                $table->text('reason')->nullable();
                $table->string('status', 32)->default('pending');
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->text('review_notes')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'course_id']);
                $table->index(['student_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('study_shift_change_requests');
    }
};
