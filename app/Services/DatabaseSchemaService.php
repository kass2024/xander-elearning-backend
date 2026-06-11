<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSchemaService
{
    /** @var array<string, list<string>> */
    private array $requiredSchema = [
        'users' => ['id', 'name', 'email', 'password', 'role', 'status', 'phone'],
        'students' => ['id', 'email', 'first_name', 'last_name', 'status', 'password', 'country', 'phone', 'primary_goal'],
        'courses' => ['id', 'title', 'status', 'price'],
        'course_enrollments' => ['id', 'student_id', 'course_id', 'status', 'level'],
        'course_payments' => ['id', 'course_id', 'student_id', 'amount_cents', 'status', 'provider'],
        'assign_cours' => ['user_id', 'course_id'],
        'meeting_registrations' => ['id', 'email', 'status'],
        'available_schedules' => ['id'],
        'livezoom_cohort' => ['id'],
    ];

    public function databaseConnected(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function pendingMigrations(): array
    {
        if (!$this->databaseConnected()) {
            return [];
        }

        $files = app('migrator')->getMigrationFiles(database_path('migrations'));
        $ran = [];

        if (Schema::hasTable('migrations')) {
            $ran = DB::table('migrations')->pluck('migration')->all();
        }

        return array_values(array_diff(array_keys($files), $ran));
    }

    /**
     * @return array{success: bool, output: string, pending_before: int, pending_after: int}
     */
    public function runMigrations(): array
    {
        $pendingBefore = count($this->pendingMigrations());

        Artisan::call('migrate', ['--force' => true]);

        return [
            'success' => true,
            'output' => trim(Artisan::output()),
            'pending_before' => $pendingBefore,
            'pending_after' => count($this->pendingMigrations()),
        ];
    }

    /**
     * @return array<string, array{exists: bool, missing_columns: list<string>}>
     */
    public function verifySchema(): array
    {
        $report = [];

        foreach ($this->requiredSchema as $table => $columns) {
            if (!Schema::hasTable($table)) {
                $report[$table] = [
                    'exists' => false,
                    'missing_columns' => $columns,
                ];
                continue;
            }

            $missing = [];
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    $missing[] = $column;
                }
            }

            $report[$table] = [
                'exists' => true,
                'missing_columns' => $missing,
            ];
        }

        return $report;
    }

    public function schemaReady(): bool
    {
        foreach ($this->verifySchema() as $entry) {
            if (!$entry['exists'] || count($entry['missing_columns']) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $connected = $this->databaseConnected();
        $pending = $connected ? $this->pendingMigrations() : [];
        $schema = $connected ? $this->verifySchema() : [];

        return [
            'database_connected' => $connected,
            'migrations_pending' => count($pending),
            'pending_migrations' => $pending,
            'schema_ready' => $connected && $this->schemaReady(),
            'schema' => $schema,
            'auto_migrate_enabled' => (bool) config('app.auto_migrate'),
        ];
    }
}
