<?php

/**
 * Verify quiz/assessment DB schema on production after migrate.
 * Usage: php scripts/verify-quiz-db.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;

$checks = [
    ['table', 'quiz_attempts', null],
    ['table', 'quiz_material_analyses', null],
    ['column', 'quiz_attempts', 'marking_provider'],
    ['column', 'quiz_attempts', 'question_results'],
    ['column', 'quiz_attempts', 'answers'],
    ['column', 'quiz_attempts', 'marked_at'],
    ['column', 'quiz_attempts', 'delivered_question_ids'],
    ['column', 'quiz_material_analyses', 'knowledge_map'],
    ['column', 'quiz_material_analyses', 'chunk_embeddings'],
    ['column', 'quiz_material_analyses', 'embedding_model'],
];

$failed = 0;

foreach ($checks as [$type, $table, $column]) {
    if ($type === 'table') {
        $ok = Schema::hasTable($table);
        $label = "table {$table}";
    } else {
        $ok = Schema::hasTable($table) && Schema::hasColumn($table, $column);
        $label = "column {$table}.{$column}";
    }

    echo ($ok ? '[OK]  ' : '[FAIL]') . " {$label}\n";
    if (!$ok) {
        $failed++;
    }
}

$rows = Illuminate\Support\Facades\DB::table('migrations')->orderByDesc('id')->limit(8)->pluck('migration');
echo "\nLast applied migrations:\n";
foreach ($rows as $migration) {
    echo '  - ' . $migration . "\n";
}

if ($failed > 0) {
    echo "\n{$failed} check(s) FAILED — run: php artisan migrate --force\n";
    exit(1);
}

echo "\nAll quiz schema checks passed.\n";
exit(0);
