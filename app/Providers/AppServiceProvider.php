<?php

namespace App\Providers;

use App\Services\DatabaseSchemaService;
use App\Services\InstitutionMailResolver;
use App\Services\MailDeliveryService;
use App\Services\StripePaymentService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatabaseSchemaService::class);
        $this->app->singleton(MailDeliveryService::class);
        $this->app->singleton(InstitutionMailResolver::class);
        $this->app->singleton(StripePaymentService::class);
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

        if (!config('app.auto_migrate')) {
            return;
        }

        if ($this->app->runningInConsole() && !DatabaseSchemaService::shouldAutoMigrateCli()) {
            return;
        }

        try {
            /** @var DatabaseSchemaService $schema */
            $schema = $this->app->make(DatabaseSchemaService::class);

            if (!$schema->databaseConnected()) {
                return;
            }

            $schema->ensureMigrated();
            $schema->ensureDemoData();
        } catch (\Throwable $e) {
            Log::warning('AUTO_MIGRATE skipped', ['error' => $e->getMessage()]);
        }
    }
}
