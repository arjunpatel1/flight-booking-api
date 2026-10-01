<?php

namespace Modules\Saas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Branch\Models\Branch;
use Modules\Saas\Services\Provisioning\Concerns\RunsTenantProvisioningStep;
use Modules\SeatingPlan\Models\Floor;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\Zone;

class WarmTenantCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsTenantProvisioningStep, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;
    public array $backoff = [10, 30];

    public function __construct(public readonly int $runId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $this->runStep($this->runId, 'cache_warmed', function ($run): void {
            $branch = Branch::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $run->tenant_id)
                ->first();

            if (! $branch) {
                return;
            }

            Floor::query()->withOutGlobalBranchPermission()->where('branch_id', $branch->id)->count();
            Zone::query()->withOutGlobalBranchPermission()->where('branch_id', $branch->id)->count();
            Table::query()->withOutGlobalBranchPermission()->where('branch_id', $branch->id)->count();
        });
    }
}
