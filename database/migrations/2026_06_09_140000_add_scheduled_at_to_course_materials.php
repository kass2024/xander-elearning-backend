<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('course_materials')) {
            return;
        }

        Schema::table('course_materials', function (Blueprint $table) {
            if (!Schema::hasColumn('course_materials', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable()->after('resource_url');
            }
            if (!Schema::hasColumn('course_materials', 'metadata')) {
                $table->json('metadata')->nullable()->after('scheduled_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('course_materials')) {
            return;
        }

        Schema::table('course_materials', function (Blueprint $table) {
            if (Schema::hasColumn('course_materials', 'metadata')) {
                $table->dropColumn('metadata');
            }
            if (Schema::hasColumn('course_materials', 'scheduled_at')) {
                $table->dropColumn('scheduled_at');
            }
        });
    }
};
