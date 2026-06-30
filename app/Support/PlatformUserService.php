<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class PlatformUserService
{
    public const ADMIN_EMAIL = 'infos@parrotglobalstudyacademy.ca';

    /** @var array<string, string> */
    private const LEGACY_EMAIL_ALIASES = [
        'info@xanderglobalscholars.com' => self::ADMIN_EMAIL,
        'admin@parrot.com' => self::ADMIN_EMAIL,
    ];

    /** @var list<string> */
    private const LEGACY_EMAILS_TO_DELETE = [
        'info@xanderglobalscholars.com',
        'admin@parrot.com',
    ];

    public static function adminEmail(): string
    {
        return self::ADMIN_EMAIL;
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

        return self::LEGACY_EMAIL_ALIASES[$normalized] ?? $normalized;
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

        User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->update(['password' => Hash::make($plain)]);
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

            $adminEmail = self::adminEmail();
            $admin = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$adminEmail])->first();
            if (!$admin) {
                User::create([
                    'email' => $adminEmail,
                    'name' => 'Parrot Canada Visa Consultant',
                    'password' => $plain,
                    'role' => 'admin',
                    'status' => 'Active',
                ]);
                $counts['users']++;
            } else {
                $admin->fill(['role' => 'admin', 'status' => 'Active']);
                $admin->password = $plain;
                $admin->save();
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

        foreach (self::LEGACY_EMAILS_TO_DELETE as $legacy) {
            User::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($legacy))])
                ->delete();
        }
    }
}
