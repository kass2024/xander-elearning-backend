<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PlatformUserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ResetAdminPassword extends Command
{
    protected $signature = 'users:reset-admin-password
                            {--password= : Plain password (defaults to SEED_PLATFORM_PASSWORD in .env)}
                            {--email= : Admin email (defaults to platform admin email)}';

    protected $description = 'Reset the platform admin login password without affecting other users';

    public function handle(): int
    {
        if (!Schema::hasTable('users')) {
            $this->error('users table does not exist. Run migrations first.');

            return self::FAILURE;
        }

        $email = PlatformUserService::normalizeEmail(
            (string) ($this->option('email') ?: PlatformUserService::adminEmail())
        );
        $plain = trim(
            (string) ($this->option('password') ?: PlatformUserService::seedPassword()),
            " \t\n\r\0\x0B'\""
        );

        if ($plain === '') {
            $this->error('Password cannot be empty. Set SEED_PLATFORM_PASSWORD in .env or pass --password=');

            return self::FAILURE;
        }

        $user = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();

        if (!$user) {
            $user = User::create([
                'email' => $email,
                'name' => 'Parrot Canada Visa Consultant',
                'password' => $plain,
                'role' => 'admin',
                'status' => 'Active',
            ]);
            $this->info("Created admin user {$email}");
        } else {
            PlatformUserService::resetPasswordForEmail($email, $plain);
            $user->refresh();
            $this->info("Password reset for {$email}");
        }

        $matches = PlatformUserService::verifyPassword($user, $plain) ? 'yes' : 'no';
        $this->newLine();
        $this->line('Login credentials:');
        $this->line("  Email:    {$email}");
        $this->line("  Password: {$plain}");
        $this->line("  Verified: {$matches}");

        return $matches === 'yes' ? self::SUCCESS : self::FAILURE;
    }
}
