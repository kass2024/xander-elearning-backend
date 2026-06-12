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
            if (!Schema::hasColumn('webinar_settings', 'zoom_meeting_id')) {
                $table->string('zoom_meeting_id')->nullable()->after('recording_enabled');
            }
            if (!Schema::hasColumn('webinar_settings', 'zoom_join_url')) {
                $table->text('zoom_join_url')->nullable()->after('zoom_meeting_id');
            }
            if (!Schema::hasColumn('webinar_settings', 'zoom_start_url')) {
                $table->text('zoom_start_url')->nullable()->after('zoom_join_url');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('webinar_settings')) {
            return;
        }

        Schema::table('webinar_settings', function (Blueprint $table) {
            foreach (['zoom_start_url', 'zoom_join_url', 'zoom_meeting_id'] as $column) {
                if (Schema::hasColumn('webinar_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
