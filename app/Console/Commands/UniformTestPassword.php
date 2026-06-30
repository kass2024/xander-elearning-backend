<?php

namespace App\Console\Commands;

use App\Support\PlatformUserService;
use Illuminate\Console\Command;

class UniformTestPassword extends Command
{
    protected $signature = 'users:uniform-test-password
                            {--password= : Plain password for every account (default: SEED_PLATFORM_PASSWORD or admin123)}
                            {--force : Apply without confirmation}';

    protected $description = 'Set the same login password on all users, students, and agents (testing only)';

    public function handle(): int
    {
        if (!$this->option('force') && !$this->confirm('This resets EVERY account password. Continue?', true)) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        $plain = $this->option('password');
        $result = PlatformUserService::resetAllPasswordsForTesting(
            is_string($plain) && trim($plain) !== '' ? trim($plain) : null
        );

        $this->info('Uniform test password applied.');
        $this->table(
            ['Table', 'Rows updated'],
            [
                ['users', (string) $result['users']],
                ['students', (string) $result['students']],
                ['agents', (string) $result['agents']],
            ]
        );

        $this->newLine();
        $this->line('Use this password for every login:');
        $this->line("  Password: {$result['password']}");
        $this->line('  Main admin emails:');
        $this->line('    info@xanderglobalscholars.com');
        $this->line('    ' . PlatformUserService::adminEmail());
        $this->line('  Partner demo: partner@acme-language.demo / partner@global-scholars.demo');

        return self::SUCCESS;
    }
}
