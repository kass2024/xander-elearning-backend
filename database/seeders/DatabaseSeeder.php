<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\PlatformUserService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $plainPassword = PlatformUserService::seedPassword();
        $adminEmail = PlatformUserService::adminEmail();

        PlatformUserService::dedupeDuplicateEmails();
        PlatformUserService::deleteLegacyEmails();

        self::seedPlatformUser(
            $adminEmail,
            PlatformUserService::adminDisplayName(),
            'admin',
            $plainPassword
        );

        self::seedPlatformUser(
            'instructor@xanderglobalscholars.com',
            'Instructor User',
            'instructor',
            $plainPassword
        );

        self::seedPlatformUser(
            'staff@xanderglobalscholars.com',
            'Staff User',
            'staff',
            $plainPassword
        );

        $this->call([
            AvailableScheduleSeeder::class,
            LearningHubDemoSeeder::class,
            PlatformInstitutionSeeder::class,
        ]);
    }

    private static function seedPlatformUser(
        string $email,
        string $name,
        string $role,
        string $plainPassword
    ): void {
        $user = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($email))])->first();

        if (!$user) {
            User::create([
                'email' => $email,
                'name' => $name,
                'password' => $plainPassword,
                'role' => $role,
                'status' => 'Active',
            ]);

            return;
        }

        $user->fill([
            'name' => $name,
            'role' => $role,
            'status' => 'Active',
        ]);
        $user->password = $plainPassword;
        $user->save();
    }
}
