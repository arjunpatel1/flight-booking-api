<?php

namespace Modules\Saas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\Saas\Services\Provisioning\Concerns\RunsTenantProvisioningStep;
use Modules\Saas\Services\Provisioning\TenantClientConfigService;

class GenerateTenantActivationAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsTenantProvisioningStep, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public array $backoff = [5, 15, 30];

    public function __construct(public readonly int $runId)
    {
        $this->afterCommit();
    }

    public function handle(TenantClientConfigService $config): void
    {
        $this->runStep($this->runId, 'client_config_ready', function ($run, $tenant) use ($config): void {
            Storage::disk('local')->put(
                "tenants/{$tenant->id}/waiter-client-config.json",
                json_encode($config->config($tenant), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            Storage::disk('local')->put(
                "tenants/{$tenant->id}/waiter-activation.json",
                json_encode($config->activationPayload($tenant), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        });
    }
}
