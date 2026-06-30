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

        PlatformUserService::dedupeDuplicateEmails();
        PlatformUserService::deleteLegacyEmails();

        self::seedPlatformUser(
            'infos@parrotglobalstudyacademy.ca',
            'Parrot Canada Visa Consultant',
            'admin',
            $plainPassword
        );

        self::seedPlatformUser(
            'instructor@parrotglobalstudyacademy.ca',
            'Instructor User',
            'instructor',
            $plainPassword
        );

        self::seedPlatformUser(
            'staff@parrotglobalstudyacademy.ca',
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
        $user->save();
    }
}
