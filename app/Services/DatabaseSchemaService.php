<?php



namespace App\Services;



use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Schema;

use App\Models\User;



class DatabaseSchemaService

{

    private const MAX_MIGRATE_ATTEMPTS = 5;



    /** @return array<string, list<string>> */

    private function requiredSchema(): array

    {

        $schema = config('database_schema');



        return is_array($schema) ? $schema : [];

    }



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

     * @return array{success: bool, output: string, pending_before: int, pending_after: int, attempts: int}

     */

    public function runMigrations(): array

    {

        $pendingBefore = count($this->pendingMigrations());

        $attempts = 0;

        $output = [];



        while ($attempts < self::MAX_MIGRATE_ATTEMPTS) {

            $pending = $this->pendingMigrations();

            if (count($pending) === 0) {

                break;

            }



            $attempts++;

            Artisan::call('migrate', ['--force' => true]);

            $output[] = trim(Artisan::output());

        }



        \App\Support\StorageLinkHelper::ensure();



        return [

            'success' => count($this->pendingMigrations()) === 0,

            'output' => implode("\n", array_filter($output)),

            'pending_before' => $pendingBefore,

            'pending_after' => count($this->pendingMigrations()),

            'attempts' => $attempts,

        ];

    }



    /**

     * @return array<string, array{exists: bool, missing_columns: list<string>}>

     */

    public function verifySchema(): array

    {

        $report = [];



        foreach ($this->requiredSchema() as $table => $columns) {

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

        if (count($this->pendingMigrations()) > 0) {

            return false;

        }



        foreach ($this->verifySchema() as $entry) {

            if (!$entry['exists'] || count($entry['missing_columns']) > 0) {

                return false;

            }

        }



        return true;

    }



    /** Run pending migrations until schema is complete (web + CLI deploy). */

    public function ensureMigrated(): array

    {

        if (!$this->databaseConnected()) {

            return ['success' => false, 'skipped' => 'database_unreachable'];

        }



        if ($this->schemaReady()) {

            return ['success' => true, 'skipped' => 'already_up_to_date'];

        }



        $result = $this->runMigrations();



        if (!$this->schemaReady() && count($this->pendingMigrations()) > 0) {

            Log::warning('AUTO_MIGRATE incomplete after run', [

                'pending' => $this->pendingMigrations(),

                'schema' => $this->verifySchema(),

            ]);

        }



        return array_merge($result, [

            'schema_ready' => $this->schemaReady(),

        ]);

    }



    /** Seed demo instructors/courses/students when the hub has no instructors yet. */

    public function ensureDemoData(): array

    {

        if (!config('app.auto_seed_demo', true)) {

            return ['success' => true, 'skipped' => 'auto_seed_disabled'];

        }



        if (!$this->databaseConnected() || !$this->schemaReady()) {

            return ['success' => false, 'skipped' => 'schema_not_ready'];

        }



        if (!Schema::hasTable('users') || !Schema::hasTable('courses')) {

            return ['success' => false, 'skipped' => 'missing_tables'];

        }



        $adminCount = User::query()->where('role', 'admin')->count();
        $instructorCount = User::query()->where('role', 'instructor')->count();

        if ($adminCount === 0) {
            try {
                Artisan::call('db:seed', [
                    '--class' => 'Database\\Seeders\\DatabaseSeeder',
                    '--force' => true,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Platform user seed failed', ['error' => $e->getMessage()]);
            }
        }

        $this->ensureInstitutionSamples();

        if ($instructorCount > 0) {

            $this->ensureInstitutionSamples();

            return ['success' => true, 'skipped' => 'already_has_instructors'];

        }



        try {

            Artisan::call('db:seed', [

                '--class' => 'Database\\Seeders\\LearningHubDemoSeeder',

                '--force' => true,

            ]);



            return [

                'success' => true,

                'seeded' => true,

                'output' => trim(Artisan::output()),

            ];

        } catch (\Throwable $e) {

            Log::warning('AUTO_SEED_DEMO failed', ['error' => $e->getMessage()]);



            return ['success' => false, 'error' => $e->getMessage()];

        }

    }



    public function ensureInstitutionSamples(): array

    {

        if (!Schema::hasTable('platform_institutions')) {

            return ['success' => true, 'skipped' => 'no_institution_table'];

        }



        if (\App\Models\PlatformInstitution::query()->count() > 0) {

            return ['success' => true, 'skipped' => 'already_has_institutions'];

        }



        try {

            Artisan::call('db:seed', [

                '--class' => 'Database\\Seeders\\PlatformInstitutionSeeder',

                '--force' => true,

            ]);



            return ['success' => true, 'seeded' => true, 'output' => trim(Artisan::output())];

        } catch (\Throwable $e) {

            Log::warning('AUTO_SEED institutions failed', ['error' => $e->getMessage()]);



            return ['success' => false, 'error' => $e->getMessage()];

        }

    }



    public static function shouldAutoMigrateCli(?array $argv = null): bool

    {

        if (!static::autoMigrateEnabled()) {

            return false;

        }



        $argv = $argv ?? ($_SERVER['argv'] ?? []);

        $command = $argv[1] ?? '';



        $skip = [

            'migrate',

            'migrate:rollback',

            'migrate:refresh',

            'migrate:fresh',

            'migrate:status',

            'migrate:install',

            'db:seed',

            'db:wipe',

            'tinker',

            'schema:dump',

        ];



        return !in_array($command, $skip, true);

    }



    private static function autoMigrateEnabled(): bool

    {

        try {

            if (function_exists('app') && app()->bound('config')) {

                return (bool) config('app.auto_migrate', true);

            }

        } catch (\Throwable) {

            // Laravel not booted yet (e.g. early artisan bootstrap).

        }



        return filter_var(env('AUTO_MIGRATE', true), FILTER_VALIDATE_BOOLEAN);

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


