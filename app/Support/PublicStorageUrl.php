<?php

namespace App\Support;

class PublicStorageUrl
{
    /** Public path served from Laravel `public/storage` (symlink). */
    public static function fromPath(?string $relativePath): ?string
    {
        $relativePath = trim((string) $relativePath, " \t\n\r\0\x0B/");
        if ($relativePath === '') {
            return null;
        }

        return '/storage/' . $relativePath;
    }

    /** Normalize stored absolute or relative logo URLs to `/storage/...`. */
    public static function normalize(?string $urlOrPath): ?string
    {
        $value = trim((string) $urlOrPath);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '/storage/')) {
            return $value;
        }

        if (str_starts_with($value, 'storage/')) {
            return '/' . $value;
        }

        if (str_starts_with($value, 'uploads/')) {
            return self::fromPath($value);
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (is_string($path) && str_contains($path, '/storage/')) {
            $pos = strpos($path, '/storage/');

            return substr($path, $pos);
        }

        return $value;
    }

    /** Absolute URL for institution logos in Zoom SDK / external clients. */
    public static function toApiAbsoluteUrl(?string $urlOrPath): ?string
    {
        $normalized = self::normalize($urlOrPath);
        if ($normalized === null || $normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            return $normalized;
        }

        $relative = ltrim($normalized, '/');
        if (str_starts_with($relative, 'storage/')) {
            $relative = substr($relative, strlen('storage/'));
        }

        if (!str_starts_with($relative, 'uploads/')) {
            return $normalized;
        }

        $apiBase = rtrim((string) config('app.url'), '/');
        if (str_ends_with($apiBase, '/public')) {
            $apiBase = substr($apiBase, 0, -strlen('/public'));
        }

        return $apiBase . '/api/admin/public-storage/' . $relative;
    }
}
