<?php

namespace Database\Seeders;

use App\Models\InstitutionPayment;
use App\Models\InstitutionPromoCode;
use App\Models\PlatformInstitution;
use App\Models\User;
use App\Support\PlatformUserService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PlatformInstitutionSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('platform_institutions')) {
            return;
        }

        $password = env('SEED_PARTNER_PASSWORD', PlatformUserService::seedPassword());

        InstitutionPromoCode::updateOrCreate(
            ['code' => 'PARTNER-DEMO-2026'],
            [
                'label' => 'Demo partner onboarding (unpaid until admin approves)',
                'max_uses' => 100,
                'uses_count' => 0,
                'is_active' => true,
            ],
        );

        $this->seedInstitution(
            slug: 'acme-language-academy',
            name: 'Acme Language Academy',
            email: 'partner@acme-language.demo',
            ownerName: 'Acme Partner Admin',
            password: $password,
            status: 'active',
            paymentStatus: 'paid',
            userStatus: 'Active',
        );

        $this->seedInstitution(
            slug: 'global-scholars-partner',
            name: 'Global Scholars Partner',
            email: 'partner@global-scholars.demo',
            ownerName: 'Global Scholars Owner',
            password: $password,
            status: 'active',
            paymentStatus: 'unpaid',
            userStatus: 'Active',
        );
    }

    private function seedInstitution(
        string $slug,
        string $name,
        string $email,
        string $ownerName,
        string $password,
        string $status,
        string $paymentStatus,
        string $userStatus,
    ): void {
        $institution = PlatformInstitution::query()->where('slug', $slug)->first();

        if (!$institution) {
            $institution = PlatformInstitution::create([
                'name' => $name,
                'slug' => $slug,
                'contact_email' => $email,
                'contact_phone' => '+1 555 0100',
                'website' => 'https://example.edu',
                'status' => $status,
                'payment_status' => $paymentStatus,
                'signup_fee_cents' => (int) config('institution.signup_fee_cents', 9900),
                'currency' => config('institution.signup_currency', 'usd'),
                'approved_at' => $status === 'active' ? now() : null,
            ]);
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
        if (!$user) {
            $user = User::create([
                'name' => $ownerName,
                'email' => $email,
                'password' => $password,
                'role' => 'partner_company',
                'status' => $userStatus,
                'phone' => '',
                'platform_institution_id' => $institution->id,
            ]);
        } else {
            $user->fill([
                'name' => $ownerName,
                'role' => 'partner_company',
                'status' => $userStatus,
                'platform_institution_id' => $institution->id,
            ]);
            $user->password = $password;
            $user->save();
        }

        if (!$institution->owner_user_id) {
            $institution->owner_user_id = $user->id;
            $institution->save();
        }

        $this->ensureDemoLogo($institution);

        if ($paymentStatus === 'paid' && $institution->payments()->where('status', 'paid')->count() === 0) {
            InstitutionPayment::create([
                'platform_institution_id' => $institution->id,
                'amount_cents' => $institution->signup_fee_cents ?: 9900,
                'currency' => $institution->currency ?: 'usd',
                'type' => 'signup',
                'status' => 'paid',
                'paid_at' => now(),
                'metadata' => ['seed' => true],
            ]);
        }
    }

    private function ensureDemoLogo(PlatformInstitution $institution): void
    {
        if (!empty($institution->logo_path)) {
            return;
        }

        if (!function_exists('imagecreatetruecolor')) {
            return;
        }

        $size = 128;
        $image = imagecreatetruecolor($size, $size);
        if ($image === false) {
            return;
        }

        $background = imagecolorallocate($image, 37, 77, 129);
        $foreground = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, $size, $size, $background);

        $letter = strtoupper(substr(trim($institution->name), 0, 1) ?: 'I');
        imagestring($image, 5, 56, 54, $letter, $foreground);

        ob_start();
        imagepng($image);
        $binary = ob_get_clean() ?: '';
        imagedestroy($image);

        if ($binary === '') {
            return;
        }

        $path = 'uploads/seed-' . $institution->slug . '.png';
        Storage::disk('public')->put($path, $binary);
        $institution->logo_path = $path;
        $institution->save();
    }
}
