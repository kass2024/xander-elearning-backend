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

        if (Schema::hasColumn('available_schedules', 'available_on_date')) {
            return;
        }

        Schema::table('available_schedules', function (Blueprint $table) {
            $table->date('available_on_date')->nullable()->after('day_of_week');
            $table->index('available_on_date');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('available_schedules')) {
            return;
        }

        if (!Schema::hasColumn('available_schedules', 'available_on_date')) {
            return;
        }

        Schema::table('available_schedules', function (Blueprint $table) {
            $table->dropIndex(['available_on_date']);
            $table->dropColumn('available_on_date');
        });
    }
};
