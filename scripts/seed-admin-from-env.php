<?php

/**
 * Reset platform admin password from .env on cPanel (no full seed).
 *
 * Requires in .env:
 *   SEED_PLATFORM_PASSWORD=Xander@2026
 * Optional:
 *   PLATFORM_ADMIN_EMAIL=info@xanderglobalscholars.com
 *
 * Usage:
 *   php scripts/seed-admin-from-env.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\PlatformUserService;

try {
    $plain = PlatformUserService::seedPassword();
    if ($plain === '' || $plain === 'admin123') {
        echo "[WARN] SEED_PLATFORM_PASSWORD is not set in .env — using fallback password.\n";
    }

    $user = PlatformUserService::ensureAdminFromEnv($plain);
    $verified = PlatformUserService::verifyPassword($user, $plain);

    echo "[OK] Admin ready\n";
    echo "  Email:    {$user->email}\n";
    echo "  Name:     {$user->name}\n";
    echo "  Role:     {$user->role}\n";
    echo "  Verified: " . ($verified ? 'yes' : 'no') . "\n";

    exit($verified ? 0 : 1);
} catch (Throwable $e) {
    echo '[FAIL] ' . $e->getMessage() . "\n";
    exit(1);
}
