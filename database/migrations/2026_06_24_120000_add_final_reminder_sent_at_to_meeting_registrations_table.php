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

        if (!Schema::hasColumn('meeting_registrations', 'final_reminder_sent_at')) {
            Schema::table('meeting_registrations', function (Blueprint $table) {
                $table->timestamp('final_reminder_sent_at')->nullable()->after('reminder_sent_at');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('meeting_registrations')) {
            return;
        }

        if (Schema::hasColumn('meeting_registrations', 'final_reminder_sent_at')) {
            Schema::table('meeting_registrations', function (Blueprint $table) {
                $table->dropColumn('final_reminder_sent_at');
            });
        }
    }
};
