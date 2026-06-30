<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quiz_material_analyses')) {
            if (!$this->columnExists('quiz_material_analyses', 'chunk_embeddings')) {
                Schema::table('quiz_material_analyses', function (Blueprint $table) {
                    $table->json('chunk_embeddings')->nullable()->after('chunks');
                });
            }

            if (!$this->columnExists('quiz_material_analyses', 'embedding_model')) {
                Schema::table('quiz_material_analyses', function (Blueprint $table) {
                    $after = $this->columnExists('quiz_material_analyses', 'chunk_embeddings') ? 'chunk_embeddings' : 'chunks';
                    $table->string('embedding_model', 64)->nullable()->after($after);
                });
            }
        }

        if (Schema::hasTable('quiz_attempts')) {
            foreach ([
                'tab_switch_count' => fn (Blueprint $table) => $table->unsignedSmallInteger('tab_switch_count')->default(0),
                'focus_lost_seconds' => fn (Blueprint $table) => $table->unsignedInteger('focus_lost_seconds')->default(0),
                'integrity_flags' => fn (Blueprint $table) => $table->json('integrity_flags')->nullable(),
                'delivered_question_ids' => fn (Blueprint $table) => $table->json('delivered_question_ids')->nullable(),
            ] as $column => $add) {
                if (!$this->columnExists('quiz_attempts', $column)) {
                    Schema::table('quiz_attempts', $add);
                }
            }

            if (!$this->columnExists('quiz_attempts', 'marking_provider')) {
                Schema::table('quiz_attempts', function (Blueprint $table) {
                    $table->string('marking_provider')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('quiz_material_analyses')) {
            $drop = array_values(array_filter(
                ['chunk_embeddings', 'embedding_model'],
                fn (string $column) => $this->columnExists('quiz_material_analyses', $column)
            ));
            if ($drop !== []) {
                Schema::table('quiz_material_analyses', function (Blueprint $table) use ($drop) {
                    $table->dropColumn($drop);
                });
            }
        }

        if (Schema::hasTable('quiz_attempts')) {
            $drop = array_values(array_filter(
                ['tab_switch_count', 'focus_lost_seconds', 'integrity_flags', 'delivered_question_ids'],
                fn (string $column) => $this->columnExists('quiz_attempts', $column)
            ));
            if ($drop !== []) {
                Schema::table('quiz_attempts', function (Blueprint $table) use ($drop) {
                    $table->dropColumn($drop);
                });
            }
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $database = Schema::getConnection()->getDatabaseName();

        $rows = DB::select(
            'SELECT COUNT(*) AS total FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, $table, $column]
        );

        return ((int) ($rows[0]->total ?? 0)) > 0;
    }
};
