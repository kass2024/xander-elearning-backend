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
            if (!Schema::hasColumn('webinar_settings', 'zoom_scheduled_at')) {
                $table->timestamp('zoom_scheduled_at')->nullable()->after('zoom_start_url');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('webinar_settings')) {
            return;
        }

        Schema::table('webinar_settings', function (Blueprint $table) {
            if (Schema::hasColumn('webinar_settings', 'zoom_scheduled_at')) {
                $table->dropColumn('zoom_scheduled_at');
            }
        });
    }
};
