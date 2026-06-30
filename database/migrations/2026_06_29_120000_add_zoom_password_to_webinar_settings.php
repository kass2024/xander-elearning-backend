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
            if (!Schema::hasColumn('webinar_settings', 'zoom_password')) {
                $table->string('zoom_password', 64)->nullable()->after('zoom_meeting_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('webinar_settings')) {
            return;
        }

        Schema::table('webinar_settings', function (Blueprint $table) {
            if (Schema::hasColumn('webinar_settings', 'zoom_password')) {
                $table->dropColumn('zoom_password');
            }
        });
    }
};
