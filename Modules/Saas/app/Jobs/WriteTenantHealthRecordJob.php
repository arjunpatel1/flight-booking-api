<?php

namespace Modules\Saas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Saas\Services\Provisioning\Concerns\RunsTenantProvisioningStep;
use Modules\Saas\Services\Provisioning\SaasProvisioningService;

class WriteTenantHealthRecordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsTenantProvisioningStep, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public array $backoff = [5, 15, 30];

    public function __construct(public readonly int $runId)
    {
        $this->afterCommit();
    }

    public function handle(SaasProvisioningService $service): void
    {
        $this->runStep($this->runId, 'health_verified', fn ($run, $tenant) => $service->healthRecord($tenant));
    }
}
