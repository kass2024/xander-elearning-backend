<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('study_shifts')) {
            Schema::create('study_shifts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_id')->nullable();
                $table->string('name', 120);
                $table->unsignedTinyInteger('day_of_week');
                $table->string('start_time', 8);
                $table->string('end_time', 8);
                $table->string('timezone', 64)->default('Africa/Kigali');
                $table->unsignedInteger('max_students')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['course_id', 'day_of_week', 'is_active']);
            });
        }

        Schema::table('course_enrollments', function (Blueprint $table) {
            if (!Schema::hasColumn('course_enrollments', 'study_shift_id')) {
                $table->unsignedBigInteger('study_shift_id')->nullable()->after('level');
                $table->index('study_shift_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('course_enrollments', function (Blueprint $table) {
            if (Schema::hasColumn('course_enrollments', 'study_shift_id')) {
                $table->dropColumn('study_shift_id');
            }
        });

        Schema::dropIfExists('study_shifts');
    }
};
