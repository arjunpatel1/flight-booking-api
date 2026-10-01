<?php

namespace Modules\Saas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Branch\Models\Branch;
use Modules\Saas\Services\Provisioning\Concerns\RunsTenantProvisioningStep;
use Modules\Saas\Services\Provisioning\SaasProvisioningService;

class GenerateTenantSkeletonJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsTenantProvisioningStep, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly int $runId)
    {
        $this->afterCommit();
    }

    public function handle(SaasProvisioningService $service): void
    {
        $this->runStep($this->runId, 'demo_data_ready', function ($run) use ($service): void {
            $branch = Branch::query()->withoutGlobalScopes()->findOrFail($run->branch_id);
            $service->restaurantSkeleton($branch);
        });
    }
}
