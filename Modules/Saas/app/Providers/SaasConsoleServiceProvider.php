<?php

namespace Modules\Saas\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Modules\Saas\Console\Commands\ActivateTenantCommand;
use Modules\Saas\Console\Commands\AuditTenantOwnershipCommand;
use Modules\Saas\Console\Commands\BackupTenantCommand;
use Modules\Saas\Console\Commands\CreateTenantCommand;
use Modules\Saas\Console\Commands\DeleteTenantCommand;
use Modules\Saas\Console\Commands\HealthCommand;
use Modules\Saas\Console\Commands\ListTenantsCommand;
use Modules\Saas\Console\Commands\PruneCustomerAppBuildArtifactsCommand;
use Modules\Saas\Console\Commands\RecoverProvisioningRunsCommand;
use Modules\Saas\Console\Commands\RestoreTenantCommand;
use Modules\Saas\Console\Commands\RunBillingLifecycleCommand;
use Modules\Saas\Console\Commands\RunCommunicationCampaignsCommand;
use Modules\Saas\Console\Commands\SuspendTenantCommand;
use Modules\Saas\Console\Commands\SyncSystemPlansCommand;

class SaasConsoleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Registered before the console guard: the tenant welcome mail renders
        // `saas::emails.*` during ordinary web requests, not just from the CLI.
        $this->loadViewsFrom(module_path('Saas', 'resources/views'), 'saas');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            CreateTenantCommand::class,
            AuditTenantOwnershipCommand::class,
            DeleteTenantCommand::class,
            SuspendTenantCommand::class,
            ActivateTenantCommand::class,
            BackupTenantCommand::class,
            RestoreTenantCommand::class,
            HealthCommand::class,
            ListTenantsCommand::class,
            RecoverProvisioningRunsCommand::class,
            RunBillingLifecycleCommand::class,
            RunCommunicationCampaignsCommand::class,
            SyncSystemPlansCommand::class,
            PruneCustomerAppBuildArtifactsCommand::class,
        ]);

        $this->app->booted(function () {
            $this->app->make(Schedule::class)
                ->call(function () {
                    $heartbeat = now()->toIso8601String();
                    Storage::disk('local')->put('saas/scheduler-heartbeat', $heartbeat);
                    try {
                        Cache::put('saas:scheduler-heartbeat', $heartbeat, now()->addMinutes(10));
                    } catch (\Throwable) { /* File heartbeat remains authoritative while cache is unavailable. */
                    }
                })
                ->name('saas:scheduler-heartbeat')
                ->everyMinute()
                ->withoutOverlapping();

            $this->app->make(Schedule::class)
                ->command('saas:billing-lifecycle')
                ->dailyAt('02:15')
                ->withoutOverlapping()
                ->runInBackground();

            $this->app->make(Schedule::class)
                ->command('saas:recover-provisioning-runs')
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->runInBackground();

            $this->app->make(Schedule::class)
                ->command('saas:backup')
                ->dailyAt('03:15')
                ->withoutOverlapping()
                ->runInBackground();

            $this->app->make(Schedule::class)->command('saas:run-communication-campaigns')->everyMinute()->withoutOverlapping();
            $this->app->make(Schedule::class)
                ->command('saas:prune-customer-app-build-artifacts')
                ->dailyAt('04:10')->withoutOverlapping();
        });
    }
}
