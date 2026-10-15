<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AksaraConfig;
use App\Services\Auditor;
use App\Services\AuditorInterface;
use App\Services\AuditWorker;
use App\Services\AuditWorkerInterface;
use App\Services\BridgeClient;
use App\Services\BridgeClientInterface;
use App\Services\JobStore;
use App\Services\ReportRendererInterface;
use App\Services\SecurityService;
use App\Services\StubReportRenderer;
use Illuminate\Support\ServiceProvider;

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

        $this->app->singleton(BridgeClientInterface::class, function () {
            return new BridgeClient();
        });

        $this->app->singleton(ReportRendererInterface::class, function () {
            return new StubReportRenderer();
        });

        $this->app->singleton(AuditorInterface::class, function ($app) {
            return new Auditor(
                $app->make(BridgeClientInterface::class),
                $app->make(ReportRendererInterface::class)
            );
        });

        $this->app->singleton(AuditWorkerInterface::class, function ($app) {
            $config = $app->make(AksaraConfig::class);

            return new AuditWorker(
                $app->make(JobStore::class),
                $app->make(AuditorInterface::class),
                $config->timeoutMs,
                $config->concurrency
            );
        });

        $this->app->alias(AuditWorkerInterface::class, AuditWorker::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Pragmas are configured in config/database.php and JobStore
    }
}
