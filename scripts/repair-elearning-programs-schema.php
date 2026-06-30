<?php

/**
 * Repair partial/failed elearning_programs migration on production.
 * Safe to run multiple times.
 *
 * Usage: php scripts/repair-elearning-programs-schema.php
 * Then:  php artisan migrate --force
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$migrationName = '2026_06_28_100000_create_elearning_programs_table';

function foreignKeyExists(string $table, string $constraint): bool
{
    $database = Schema::getConnection()->getDatabaseName();

    return DB::table('information_schema.TABLE_CONSTRAINTS')
        ->where('CONSTRAINT_SCHEMA', $database)
        ->where('TABLE_NAME', $table)
        ->where('CONSTRAINT_NAME', $constraint)
        ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
        ->exists();
}

echo "Repairing elearning_programs schema...\n";

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
    echo "[OK] Created elearning_programs table\n";
} else {
    DB::statement('ALTER TABLE `elearning_programs` ENGINE=InnoDB');
    echo "[OK] elearning_programs exists (InnoDB ensured)\n";
}

if (!Schema::hasTable('courses')) {
    echo "[WARN] courses table missing — skipping program_id FK\n";
} else {
    DB::statement('ALTER TABLE `courses` ENGINE=InnoDB');

    if (!Schema::hasColumn('courses', 'program_id')) {
        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedBigInteger('program_id')->nullable()->after('id');
        });
        echo "[OK] Added courses.program_id\n";
    } else {
        DB::statement('ALTER TABLE `courses` MODIFY `program_id` BIGINT UNSIGNED NULL');
        echo "[OK] Normalized courses.program_id to BIGINT UNSIGNED NULL\n";
    }

    if (!foreignKeyExists('courses', 'courses_program_id_foreign')) {
        Schema::table('courses', function (Blueprint $table) {
            $table->foreign('program_id', 'courses_program_id_foreign')
                ->references('id')
                ->on('elearning_programs')
                ->nullOnDelete();
        });
        echo "[OK] Added courses_program_id_foreign\n";
    } else {
        echo "[OK] Foreign key courses_program_id_foreign already exists\n";
    }
}

if (!DB::table('migrations')->where('migration', $migrationName)->exists()) {
    $batch = (int) DB::table('migrations')->max('batch') + 1;
    DB::table('migrations')->insert([
        'migration' => $migrationName,
        'batch' => $batch,
    ]);
    echo "[OK] Marked {$migrationName} as migrated (batch {$batch})\n";
} else {
    echo "[OK] {$migrationName} already recorded in migrations\n";
}

echo "\nDone. Run: php artisan migrate --force\n";
