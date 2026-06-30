<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('webinar_settings')) {
            return;
        }

        Schema::table('webinar_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('webinar_settings', 'calendar_blocked_months')) {
                $table->json('calendar_blocked_months')->nullable()->after('session_started_at');
            }
            if (!Schema::hasColumn('webinar_settings', 'calendar_blocked_dates')) {
                $table->json('calendar_blocked_dates')->nullable()->after('calendar_blocked_months');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('webinar_settings')) {
            return;
        }

        Schema::table('webinar_settings', function (Blueprint $table) {
            if (Schema::hasColumn('webinar_settings', 'calendar_blocked_dates')) {
                $table->dropColumn('calendar_blocked_dates');
            }
            if (Schema::hasColumn('webinar_settings', 'calendar_blocked_months')) {
                $table->dropColumn('calendar_blocked_months');
            }
        });
    }
};
