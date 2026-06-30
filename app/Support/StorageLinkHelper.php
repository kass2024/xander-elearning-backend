<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class StorageLinkHelper
{
    /** Ensure `public/storage` symlinks to `storage/app/public` (fixes empty folder on Windows). */
    public static function ensure(): bool
    {
        $link = public_path('storage');
        $target = storage_path('app/public');

        if (!is_dir($target)) {
            File::ensureDirectoryExists($target);
        }

        if (is_link($link)) {
            return true;
        }

        if (is_dir($link)) {
            $entries = @scandir($link);
            $isEmpty = $entries === false || count(array_diff($entries, ['.', '..'])) === 0;
            if ($isEmpty) {
                @rmdir($link);
            } else {
                return true;
            }
        }

        if (file_exists($link)) {
            return true;
        }

        try {
            Artisan::call('storage:link');

            return is_link($link) || is_dir($link);
        } catch (\Throwable) {
            return false;
        }
    }
}
