<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('available_schedules')) {
            return;
        }

        if (!Schema::hasColumn('available_schedules', 'meeting_duration_minutes')) {
            Schema::table('available_schedules', function (Blueprint $table) {
                $table->unsignedSmallInteger('meeting_duration_minutes')->default(60)->after('end_time');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('available_schedules')) {
            return;
        }

        if (Schema::hasColumn('available_schedules', 'meeting_duration_minutes')) {
            Schema::table('available_schedules', function (Blueprint $table) {
                $table->dropColumn('meeting_duration_minutes');
            });
        }
    }
};
