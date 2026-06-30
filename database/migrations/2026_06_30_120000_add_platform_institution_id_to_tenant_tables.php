<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['elearning_programs', 'courses', 'livezoom_cohort', 'study_shifts'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'platform_institution_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedBigInteger('platform_institution_id')->nullable()->after('id');
                    $blueprint->index('platform_institution_id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['elearning_programs', 'courses', 'livezoom_cohort', 'study_shifts'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'platform_institution_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('platform_institution_id');
                });
            }
        }
    }
};
