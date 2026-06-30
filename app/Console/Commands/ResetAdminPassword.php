<?php

namespace App\Console\Commands;

use App\Support\PlatformUserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ResetAdminPassword extends Command
{
    protected $signature = 'users:reset-admin-password
                            {--password= : Plain password (defaults to SEED_PLATFORM_PASSWORD in .env)}
                            {--email= : Admin email (defaults to PLATFORM_ADMIN_EMAIL or info@xanderglobalscholars.com)}';

    protected $description = 'Reset the platform admin login password without affecting other users';

    public function handle(): int
    {
        if (!Schema::hasTable('users')) {
            $this->error('users table does not exist. Run migrations first.');

            return self::FAILURE;
        }

        $emailOption = trim((string) $this->option('email'));
        $plain = trim(
            (string) ($this->option('password') ?: PlatformUserService::seedPassword()),
            " \t\n\r\0\x0B'\""
        );

        if ($plain === '') {
            $this->error('Password cannot be empty. Set SEED_PLATFORM_PASSWORD in .env or pass --password=');

            return self::FAILURE;
        }

        if ($emailOption === '') {
            $user = PlatformUserService::ensureAdminFromEnv($plain);
            $email = PlatformUserService::adminEmail();
            $this->info("Admin password set for {$email}");
        } else {
            $email = PlatformUserService::normalizeEmail($emailOption);
            PlatformUserService::resetPasswordForEmail($email, $plain);
            $user = \App\Models\User::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                ->first();

            if (!$user) {
                $this->error("No user found for {$email}");

                return self::FAILURE;
            }

            $this->info("Password reset for {$email}");
        }

        $matches = PlatformUserService::verifyPassword($user, $plain) ? 'yes' : 'no';
        $this->newLine();
        $this->line('Login credentials:');
        $this->line("  Email:    {$user->email}");
        $this->line("  Password: {$plain}");
        $this->line("  Verified: {$matches}");

        return $matches === 'yes' ? self::SUCCESS : self::FAILURE;
    }
}
