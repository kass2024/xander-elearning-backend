<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('elearning_programs')) {
            return;
        }

        $generalId = DB::table('elearning_programs')->where('name', 'General')->value('id');

        if (!$generalId) {
            $generalId = DB::table('elearning_programs')->insertGetId([
                'name' => 'General',
                'description' => 'Default program for existing courses',
                'status' => 'Active',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('courses')
            ->whereNull('program_id')
            ->update(['program_id' => $generalId]);
    }

    public function down(): void
    {
        // Non-destructive: leave program assignments in place on rollback.
    }
};
