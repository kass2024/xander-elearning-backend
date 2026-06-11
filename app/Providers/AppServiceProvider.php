<?php

namespace App\Providers;

use App\Services\DatabaseSchemaService;
use App\Services\MailDeliveryService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatabaseSchemaService::class);
        $this->app->singleton(MailDeliveryService::class);
    }

    public function boot(): void
    {
        $localDomain = config('mail.mailers.smtp.local_domain');
        if (empty($localDomain) || $localDomain === 'localhost') {
            $from = config('mail.from.address');
            if (is_string($from) && str_contains($from, '@')) {
                config(['mail.mailers.smtp.local_domain' => substr(strrchr($from, '@'), 1)]);
            }
        }

        if (!config('app.auto_migrate') || $this->app->runningInConsole()) {
            return;
        }

        try {
            /** @var DatabaseSchemaService $schema */
            $schema = $this->app->make(DatabaseSchemaService::class);

            if (!$schema->databaseConnected()) {
                return;
            }

            if (count($schema->pendingMigrations()) > 0 || !$schema->schemaReady()) {
                $schema->runMigrations();
            }
        } catch (\Throwable $e) {
            Log::warning('AUTO_MIGRATE skipped', ['error' => $e->getMessage()]);
        }
    }
}
