<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ensures meeting_registrations has all columns expected by the API,
 * even when the table was created manually on production.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('meeting_registrations')) {
            return;
        }

        Schema::table('meeting_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('meeting_registrations', 'status')) {
                $table->string('status')->default('pending')->after('notes');
            }

            if (!Schema::hasColumn('meeting_registrations', 'rejected_reason')) {
                $table->text('rejected_reason')->nullable()->after('status');
            }

            if (!Schema::hasColumn('meeting_registrations', 'zoom_meeting_id')) {
                $table->string('zoom_meeting_id')->nullable()->after('rejected_reason');
            }

            if (!Schema::hasColumn('meeting_registrations', 'zoom_join_url')) {
                $table->text('zoom_join_url')->nullable()->after('zoom_meeting_id');
            }

            if (!Schema::hasColumn('meeting_registrations', 'zoom_start_time')) {
                $table->dateTime('zoom_start_time')->nullable()->after('zoom_join_url');
            }

            if (!Schema::hasColumn('meeting_registrations', 'reminder_sent_at')) {
                $table->timestamp('reminder_sent_at')->nullable()->after('zoom_start_time');
            }

            if (!Schema::hasColumn('meeting_registrations', 'available_schedule_id')) {
                $table->unsignedBigInteger('available_schedule_id')->nullable()->after('user_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('meeting_registrations')) {
            return;
        }

        Schema::table('meeting_registrations', function (Blueprint $table) {
            foreach ([
                'reminder_sent_at',
                'zoom_start_time',
                'zoom_join_url',
                'zoom_meeting_id',
                'rejected_reason',
            ] as $column) {
                if (Schema::hasColumn('meeting_registrations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
