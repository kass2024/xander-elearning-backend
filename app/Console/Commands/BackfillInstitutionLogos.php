<?php

namespace App\Console\Commands;

use App\Models\PlatformInstitution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackfillInstitutionLogos extends Command
{
    protected $signature = 'institutions:backfill-logos';

    protected $description = 'Create placeholder logos for partner institutions missing logo_path';

    public function handle(): int
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->error('GD extension is required.');

            return self::FAILURE;
        }

        $count = 0;
        PlatformInstitution::query()->whereNull('logo_path')->orWhere('logo_path', '')->each(function (PlatformInstitution $institution) use (&$count) {
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
            $count++;
            $this->line("Logo created for {$institution->name}");
        });

        $this->info("Done. {$count} institution logo(s) created.");

        return self::SUCCESS;
    }
}
