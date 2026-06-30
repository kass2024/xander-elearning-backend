#!/usr/bin/env php
<?php

/**
 * Move existing courses into e-learning programs.
 *
 * Usage:
 *   php scripts/assign-courses-to-programs.php
 *   php scripts/assign-courses-to-programs.php --dry-run
 *   php scripts/assign-courses-to-programs.php --list
 *   php scripts/assign-courses-to-programs.php --create-missing
 *   php scripts/assign-courses-to-programs.php --program="Language"
 *   php scripts/assign-courses-to-programs.php --program-id=2 --course-id=5
 *
 * Edit keyword rules in config/course_program_mapping.php before running.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$args = array_slice($argv, 1);
$exit = $kernel->call('courses:assign-programs', parseAssignOptions($args));

echo $kernel->output();
exit($exit);

function parseAssignOptions(array $args): array
{
    $options = [];
    foreach ($args as $arg) {
        if ($arg === '--dry-run') {
            $options['--dry-run'] = true;
        } elseif ($arg === '--list') {
            $options['--list'] = true;
        } elseif ($arg === '--force') {
            $options['--force'] = true;
        } elseif ($arg === '--create-missing') {
            $options['--create-missing'] = true;
        } elseif (str_starts_with($arg, '--program=')) {
            $options['--program'] = substr($arg, 10);
        } elseif (str_starts_with($arg, '--program-id=')) {
            $options['--program-id'] = (int) substr($arg, 13);
        } elseif (str_starts_with($arg, '--course-id=')) {
            $options['--course-id'] = (int) substr($arg, 12);
        }
    }

    return $options;
}
