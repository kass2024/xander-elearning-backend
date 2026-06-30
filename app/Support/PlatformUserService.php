<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class PlatformUserService
{
    public const DEFAULT_ADMIN_EMAIL = 'info@xanderglobalscholars.com';

    /** @var array<string, string> Old Parrot logins → current platform admin. */
    private const LEGACY_EMAIL_ALIASES = [
        'infos@parrotglobalstudyacademy.ca' => self::DEFAULT_ADMIN_EMAIL,
        'admin@parrot.com' => self::DEFAULT_ADMIN_EMAIL,
    ];

    /** @var list<string> */
    private const LEGACY_EMAILS_TO_DELETE = [
        'admin@parrot.com',
    ];

    public static function adminEmail(): string
    {
        $fromEnv = trim((string) env('PLATFORM_ADMIN_EMAIL', ''));

        return $fromEnv !== ''
            ? strtolower($fromEnv)
            : self::DEFAULT_ADMIN_EMAIL;
    }

    public static function adminDisplayName(): string
    {
        $fromEnv = trim((string) env('PLATFORM_ADMIN_NAME', ''));

        return $fromEnv !== '' ? $fromEnv : 'Xander Global Scholars Admin';
    }

    public static function seedPassword(): string
    {
        $fromEnv = env('SEED_PLATFORM_PASSWORD', '');
        $plain = trim((string) $fromEnv, " \t\n\r\0\x0B'\"");

        return $plain !== '' ? $plain : 'admin123';
    }

    public static function normalizeEmail(string $email): string
    {
        $normalized = strtolower(trim($email));
        $admin = self::adminEmail();

        $aliases = array_merge(self::LEGACY_EMAIL_ALIASES, [
            'infos@parrotglobalstudyacademy.ca' => $admin,
            'admin@parrot.com' => $admin,
        ]);

        return $aliases[$normalized] ?? $normalized;
    }

    public static function verifyPassword(User $user, string $plain): bool
    {
        $stored = (string) ($user->password ?? '');

        if ($stored === '') {
            return hash_equals(self::seedPassword(), $plain);
        }

        if (password_get_info($stored)['algo'] !== 0) {
            return password_verify($plain, $stored);
        }

        return hash_equals($stored, $plain);
    }

    public static function setUserPassword(User $user, string $plain): void
    {
        $user->password = trim($plain);
        $user->save();
    }

    public static function resetPasswordForEmail(string $email, string $plain): void
    {
        $email = self::normalizeEmail($email);

        $user = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->first();

        if ($user) {
            self::setUserPassword($user, $plain);
        }
    }

    /**
     * Ensure the platform admin exists and password matches SEED_PLATFORM_PASSWORD.
     */
    public static function ensureAdminFromEnv(?string $plain = null): User
    {
        $plain = trim((string) ($plain ?? self::seedPassword()), " \t\n\r\0\x0B'\"");
        if ($plain === '') {
            throw new \InvalidArgumentException('Password cannot be empty. Set SEED_PLATFORM_PASSWORD in .env.');
        }

        $email = self::adminEmail();
        $user = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();

        if (!$user) {
            $user = User::create([
                'email' => $email,
                'name' => self::adminDisplayName(),
                'password' => $plain,
                'role' => 'admin',
                'status' => 'Active',
            ]);

            return $user;
        }

        $user->fill([
            'name' => self::adminDisplayName(),
            'role' => 'admin',
            'status' => 'Active',
        ]);
        self::setUserPassword($user, $plain);

        return $user;
    }

    /**
     * Set the same bcrypt password on every login account (users, students, agents).
     * For local/staging testing only.
     *
     * @return array{password: string, users: int, students: int, agents: int}
     */
    public static function resetAllPasswordsForTesting(?string $plain = null): array
    {
        $plain = trim((string) ($plain ?? self::seedPassword()), " \t\n\r\0\x0B'\"");
        if ($plain === '') {
            $plain = 'admin123';
        }

        $hash = Hash::make($plain);
        $counts = ['users' => 0, 'students' => 0, 'agents' => 0];

        if (\Illuminate\Support\Facades\Schema::hasTable('users')) {
            self::dedupeDuplicateEmails();
            self::deleteLegacyEmails();

            $counts['users'] = User::query()->update(['password' => $hash]);

            $hadAdmin = User::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [self::adminEmail()])
                ->exists();
            self::ensureAdminFromEnv($plain);
            if (!$hadAdmin) {
                $counts['users']++;
            }
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('students')) {
            $counts['students'] = \App\Models\Student::query()->update(['password' => $hash]);
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('agents')) {
            $counts['agents'] = \App\Models\Agent::query()->update(['password' => $hash]);
        }

        return [
            'password' => $plain,
            'users' => $counts['users'],
            'students' => $counts['students'],
            'agents' => $counts['agents'],
        ];
    }

    public static function dedupeDuplicateEmails(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('users')) {
            return;
        }

        $seen = [];

        User::query()
            ->orderBy('id')
            ->get(['id', 'email'])
            ->each(function (User $user) use (&$seen) {
                $key = strtolower(trim((string) $user->email));
                if ($key === '') {
                    return;
                }

                if (isset($seen[$key])) {
                    $user->delete();

                    return;
                }

                $seen[$key] = true;
            });
    }

    public static function deleteLegacyEmails(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('users')) {
            return;
        }

        $admin = self::adminEmail();

        foreach (self::LEGACY_EMAILS_TO_DELETE as $legacy) {
            $legacy = strtolower(trim($legacy));
            if ($legacy === $admin) {
                continue;
            }

            User::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [$legacy])
                ->delete();
        }
    }
}
