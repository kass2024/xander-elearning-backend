<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (!Schema::hasColumn('courses', 'course_code')) {
                $table->string('course_code', 32)->nullable()->unique()->after('title');
            }
            if (!Schema::hasColumn('courses', 'general_information')) {
                $table->text('general_information')->nullable()->after('description');
            }
            if (!Schema::hasColumn('courses', 'important_information')) {
                $table->text('important_information')->nullable()->after('general_information');
            }
            if (!Schema::hasColumn('courses', 'guidelines')) {
                $table->json('guidelines')->nullable()->after('important_information');
            }
            if (!Schema::hasColumn('courses', 'how_to_use')) {
                $table->json('how_to_use')->nullable()->after('guidelines');
            }
            if (!Schema::hasColumn('courses', 'attendance_policy')) {
                $table->text('attendance_policy')->nullable()->after('how_to_use');
            }
            if (!Schema::hasColumn('courses', 'assessment_policy')) {
                $table->text('assessment_policy')->nullable()->after('attendance_policy');
            }
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            foreach ([
                'course_code',
                'general_information',
                'important_information',
                'guidelines',
                'how_to_use',
                'attendance_policy',
                'assessment_policy',
            ] as $column) {
                if (Schema::hasColumn('courses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
