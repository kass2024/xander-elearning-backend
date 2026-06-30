<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('livezoom_cohort', function (Blueprint $table) {
            if (!Schema::hasColumn('livezoom_cohort', 'available_on_date')) {
                $table->date('available_on_date')->nullable()->after('day_of_week');
                $table->index('available_on_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('livezoom_cohort', function (Blueprint $table) {
            if (Schema::hasColumn('livezoom_cohort', 'available_on_date')) {
                $table->dropIndex(['available_on_date']);
                $table->dropColumn('available_on_date');
            }
        });
    }
};
