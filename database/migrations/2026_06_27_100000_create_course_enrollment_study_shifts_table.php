<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('course_enrollment_study_shifts')) {
            Schema::create('course_enrollment_study_shifts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_enrollment_id');
                $table->unsignedBigInteger('study_shift_id');
                $table->timestamps();

                $table->unique(['course_enrollment_id', 'study_shift_id'], 'enrollment_shift_unique');
                $table->index('study_shift_id');
            });
        }

        if (Schema::hasTable('course_enrollments') && Schema::hasColumn('course_enrollments', 'study_shift_id')) {
            $rows = DB::table('course_enrollments')
                ->whereNotNull('study_shift_id')
                ->get(['id', 'study_shift_id']);

            foreach ($rows as $row) {
                $exists = DB::table('course_enrollment_study_shifts')
                    ->where('course_enrollment_id', $row->id)
                    ->where('study_shift_id', $row->study_shift_id)
                    ->exists();

                if (!$exists) {
                    DB::table('course_enrollment_study_shifts')->insert([
                        'course_enrollment_id' => $row->id,
                        'study_shift_id' => $row->study_shift_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_enrollment_study_shifts');
    }
};
