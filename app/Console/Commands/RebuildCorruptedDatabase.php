<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RebuildCorruptedDatabase extends Command
{
    protected $signature = 'schema:rebuild-corrupted
                            {--seed : Run database seeders after migrations}
                            {--force : Rebuild without confirmation}';

    protected $description = 'Repair InnoDB corruption (MySQL error 1932) by recreating schema';

    public function handle(): int
    {
        $connection = config('database.default', 'mysql');
        $database = (string) config("database.connections.{$connection}.database");

        if ($database === '') {
            $this->error('Database name is not configured.');

            return self::FAILURE;
        }

        if (!$this->option('force') && !$this->confirm(
            "This will erase all data in `{$database}` and rebuild tables. Continue?"
        )) {
            $this->warn('Cancelled.');

            return self::FAILURE;
        }

        $this->warn("Repairing database `{$database}`...");

        if (!$this->dropAllTables($database)) {
            return self::FAILURE;
        }

        DB::purge($connection);
        DB::reconnect($connection);

        $this->info('Running migrations...');
        Artisan::call('migrate', ['--force' => true]);
        $this->output->write(Artisan::output());

        if ($this->option('seed')) {
            $this->info('Running seeders...');
            Artisan::call('db:seed', ['--force' => true]);
            $this->output->write(Artisan::output());
        }

        $this->newLine();
        $this->info('Database rebuild complete.');

        if ($this->option('seed')) {
            $this->line('Default logins:');
            $this->line('  admin@parrot.com / 1234');
            $this->line('  info@xanderglobalscholars.com / 12345678');
        }

        return self::SUCCESS;
    }

    private function dropAllTables(string $database): bool
    {
        try {
            $tables = DB::select(
                'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = ? AND table_type = ?',
                [$database, 'BASE TABLE']
            );
        } catch (\Throwable $e) {
            $this->error('Could not list tables: ' . $e->getMessage());

            return false;
        }

        if ($tables === []) {
            $this->line('No tables found. Continuing with migrations.');

            return true;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ($tables as $row) {
            $table = (string) ($row->name ?? '');
            if ($table === '') {
                continue;
            }

            try {
                DB::statement("DROP TABLE IF EXISTS `{$table}`");
                $this->line("Dropped: {$table}");
            } catch (\Throwable $e) {
                $this->warn("Could not drop {$table}: " . $e->getMessage());
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $remaining = Schema::getTableListing();
        if ($remaining !== []) {
            $this->error('Some tables could not be dropped. Stop MySQL, delete the folder:');
            $this->line('  C:\\xampp\\mysql\\data\\' . str_replace('-', '@002d', $database));
            $this->line('Then restart MySQL and run this command again.');

            return false;
        }

        return true;
    }
}
