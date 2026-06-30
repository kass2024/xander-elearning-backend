<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function foreignKeyExists(string $table, string $constraint): bool
    {
        $database = Schema::getConnection()->getDatabaseName();

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    public function up(): void
    {
        if (!Schema::hasTable('elearning_programs')) {
            Schema::create('elearning_programs', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('image')->nullable();
                $table->string('status')->default('Active');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        } else {
            DB::statement('ALTER TABLE `elearning_programs` ENGINE=InnoDB');
        }

        if (!Schema::hasTable('courses')) {
            return;
        }

        DB::statement('ALTER TABLE `courses` ENGINE=InnoDB');

        if (!Schema::hasColumn('courses', 'program_id')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->unsignedBigInteger('program_id')->nullable()->after('id');
            });
        } else {
            DB::statement('ALTER TABLE `courses` MODIFY `program_id` BIGINT UNSIGNED NULL');
        }

        if (!$this->foreignKeyExists('courses', 'courses_program_id_foreign')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->foreign('program_id', 'courses_program_id_foreign')
                    ->references('id')
                    ->on('elearning_programs')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('courses')) {
            if ($this->foreignKeyExists('courses', 'courses_program_id_foreign')) {
                Schema::table('courses', function (Blueprint $table) {
                    $table->dropForeign('courses_program_id_foreign');
                });
            }

            if (Schema::hasColumn('courses', 'program_id')) {
                Schema::table('courses', function (Blueprint $table) {
                    $table->dropColumn('program_id');
                });
            }
        }

        Schema::dropIfExists('elearning_programs');
    }
};
