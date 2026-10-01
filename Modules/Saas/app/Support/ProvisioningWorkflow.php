<?php

namespace Modules\Saas\Support;

use Modules\Saas\Jobs\CompleteTenantProvisioningJob;
use Modules\Saas\Jobs\GenerateTenantActivationAssetsJob;
use Modules\Saas\Jobs\GenerateTenantQrAssetsJob;
use Modules\Saas\Jobs\GenerateTenantSkeletonJob;
use Modules\Saas\Jobs\GenerateTenantThemeAssetsJob;
use Modules\Saas\Jobs\PrepareTenantStorageJob;
use Modules\Saas\Jobs\WarmTenantCacheJob;
use Modules\Saas\Jobs\WriteTenantHealthRecordJob;

final class ProvisioningWorkflow
{
    public const STEPS = [
        'tenant_created',
        'branch_created',
        'admin_created',
        'subscription_created',
        'settings_ready',
        'storage_ready',
        'demo_data_ready',
        'qr_ready',
        'theme_ready',
        'client_config_ready',
        'cache_warmed',
        'health_verified',
        'completed',
    ];

    public static function definitions(): array
    {
        return [
            'tenant_created' => new ProvisioningStep('tenant_created', ProvisioningStatus::TENANT_CREATED, 'provisioning', 2, 1, false),
            'branch_created' => new ProvisioningStep('branch_created', ProvisioningStatus::BRANCH_CREATED, 'provisioning', 2, 1, false),
            'admin_created' => new ProvisioningStep('admin_created', ProvisioningStatus::ADMIN_CREATED, 'provisioning', 2, 1, false),
            'subscription_created' => new ProvisioningStep('subscription_created', ProvisioningStatus::SUBSCRIPTION_CREATED, 'provisioning', 1, 1, false),
            'settings_ready' => new ProvisioningStep('settings_ready', ProvisioningStatus::SETTINGS_READY, 'provisioning', 1, 1, false),
            'storage_ready' => new ProvisioningStep('storage_ready', ProvisioningStatus::STORAGE_READY, 'assets', 2, 5),
            'demo_data_ready' => new ProvisioningStep('demo_data_ready', ProvisioningStatus::DATABASE_READY, 'provisioning', 4, 15),
            'qr_ready' => new ProvisioningStep('qr_ready', ProvisioningStatus::QR_READY, 'assets', 1, 4),
            'theme_ready' => new ProvisioningStep('theme_ready', ProvisioningStatus::WAITER_READY, 'assets', 1, 5),
            'client_config_ready' => new ProvisioningStep('client_config_ready', ProvisioningStatus::CLIENT_CONFIG_READY, 'assets', 1, 3),
            'cache_warmed' => new ProvisioningStep('cache_warmed', ProvisioningStatus::CACHE_WARMED, 'monitoring', 1, 5),
            'health_verified' => new ProvisioningStep('health_verified', ProvisioningStatus::HEALTH_VERIFIED, 'monitoring', 1, 4),
            'completed' => new ProvisioningStep('completed', ProvisioningStatus::COMPLETED, 'provisioning', 1, 1, false),
        ];
    }

    public static function initialSteps(): array
    {
        return collect(self::definitions())->mapWithKeys(function (ProvisioningStep $step) {
            return [$step->key => [
                'status' => 'pending',
                'state' => $step->state,
                'started_at' => null,
                'completed_at' => null,
                'duration_ms' => null,
                'retry_count' => 0,
                'failure_reason' => null,
                'logs' => [],
            ]];
        })->all();
    }

    public static function jobFor(string $step, int $runId): ?object
    {
        return match ($step) {
            'storage_ready' => new PrepareTenantStorageJob($runId),
            'demo_data_ready' => new GenerateTenantSkeletonJob($runId),
            'qr_ready' => new GenerateTenantQrAssetsJob($runId),
            'theme_ready' => new GenerateTenantThemeAssetsJob($runId),
            'client_config_ready' => new GenerateTenantActivationAssetsJob($runId),
            'cache_warmed' => new WarmTenantCacheJob($runId),
            'health_verified' => new WriteTenantHealthRecordJob($runId),
            'completed' => new CompleteTenantProvisioningJob($runId),
            default => null,
        };
    }

    public static function queueFor(string $step): string
    {
        $queue = self::definitions()[$step]?->queue ?? 'provisioning';

        return config("saas.queues.{$queue}", config('saas.provisioning.queue', 'default'));
    }

    public static function pendingRunnableSteps(array $steps): array
    {
        $runnable = [
            'storage_ready',
            'demo_data_ready',
            'qr_ready',
            'theme_ready',
            'client_config_ready',
            'cache_warmed',
            'health_verified',
            'completed',
        ];

        return collect(self::STEPS)
            ->filter(fn (string $step) => in_array($step, $runnable, true))
            ->filter(fn (string $step) => ($steps[$step]['status'] ?? 'pending') !== 'completed')
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Dedicated-database stages (Phase 1 — DISABLED)
    |--------------------------------------------------------------------------
    |
    | Metadata only. These describe the stages a dedicated-database provisioning
    | run WILL have once that phase is enabled. They are intentionally NOT part
    | of self::STEPS, self::definitions() or self::jobFor(), so the live
    | provisioning flow neither sees nor runs them — the running system is
    | completely unaffected.
    |
    | `enabled => false` on every stage is the gate. When the dedicated-database
    | phase begins, these move into the real workflow behind a feature flag; they
    | do nothing here beyond letting the operator dashboard preview the plan.
    |
    */
    public const DEDICATED_DATABASE_STAGES = [
        'database_created',
        'database_user_created',
        'credentials_registered',
        'tenant_migrations_run',
        'defaults_seeded',
        'connection_health_check',
        'connection_registered',
    ];

    /**
     * @return array<int, array{key:string,label:string,description:string,enabled:bool}>
     */
    public static function dedicatedDatabaseStages(): array
    {
        return [
            ['key' => 'database_created', 'label' => 'Create database', 'description' => 'Create the tenant\'s dedicated database.', 'enabled' => false],
            ['key' => 'database_user_created', 'label' => 'Create database user', 'description' => 'Create a least-privilege user scoped to that database.', 'enabled' => false],
            ['key' => 'credentials_registered', 'label' => 'Register credentials', 'description' => 'Store encrypted credentials in the infrastructure registry.', 'enabled' => false],
            ['key' => 'tenant_migrations_run', 'label' => 'Run tenant migrations', 'description' => 'Migrate the tenant schema into the new database.', 'enabled' => false],
            ['key' => 'defaults_seeded', 'label' => 'Seed defaults', 'description' => 'Seed roles, permissions, settings and the main branch.', 'enabled' => false],
            ['key' => 'connection_health_check', 'label' => 'Health check', 'description' => 'Verify the connection before activation.', 'enabled' => false],
            ['key' => 'connection_registered', 'label' => 'Register connection', 'description' => 'Warm the resolver and mark the tenant dedicated.', 'enabled' => false],
        ];
    }

    /**
     * Hard gate. Dedicated-database provisioning stays off until this returns
     * true (a later phase flips the config flag). Nothing in Phase 1 calls it.
     */
    public static function dedicatedProvisioningEnabled(): bool
    {
        return (bool) config('saas.infrastructure.dedicated_provisioning_enabled', false);
    }
}
