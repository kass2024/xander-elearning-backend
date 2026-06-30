<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('meeting_registrations')) {
            return;
        }

        if (!Schema::hasColumn('meeting_registrations', 'schedule_label')) {
            Schema::table('meeting_registrations', function (Blueprint $table) {
                $table->string('schedule_label', 500)->nullable()->after('available_schedule_id');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('meeting_registrations')) {
            return;
        }

        if (Schema::hasColumn('meeting_registrations', 'schedule_label')) {
            Schema::table('meeting_registrations', function (Blueprint $table) {
                $table->dropColumn('schedule_label');
            });
        }
    }
};
