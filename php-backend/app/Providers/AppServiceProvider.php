<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\AksaraConfig;
use App\Services\JobStore;
use App\Services\SecurityService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AksaraConfig::class, function () {
            return new AksaraConfig();
        });

        $this->app->singleton(SecurityService::class, function () {
            return new SecurityService();
        });

        $this->app->singleton(JobStore::class, function ($app) {
            $config = $app->make(AksaraConfig::class);
            return new JobStore($config->databasePath);
        });

        $this->app->singleton(\App\Services\BridgeClientInterface::class, function () {
            return new \App\Services\BridgeClient();
        });

        $this->app->singleton(\App\Services\AuditWorkerInterface::class, function ($app) {
            return new \App\Services\AuditWorker($app->make(JobStore::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Pragmas are configured in config/database.php and JobStore
    }
}
