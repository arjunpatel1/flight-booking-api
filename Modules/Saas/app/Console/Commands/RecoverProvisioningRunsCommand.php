<?php

namespace Modules\Saas\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Saas\Models\SaasProvisioningRun;
use Modules\Saas\Services\Provisioning\ProvisioningOrchestratorService;

class RecoverProvisioningRunsCommand extends Command
{
    protected $signature = 'saas:recover-provisioning-runs
        {--stale-minutes= : Processing runs older than this are resumed}
        {--limit= : Maximum runs to inspect}
        {--dry-run : Report only without dispatching jobs}';

    protected $description = 'Resume stale SaaS provisioning runs without rerunning completed steps.';

    public function handle(ProvisioningOrchestratorService $orchestrator): int
    {
        if (! Schema::hasTable('saas_provisioning_runs')) {
            $this->warn('SaaS provisioning runs table is not available.');
            return self::SUCCESS;
        }

        $staleMinutes = max(1, (int) ($this->option('stale-minutes') ?? config('saas.provisioning.recovery_stale_minutes', 10)));
        $limit = max(1, (int) ($this->option('limit') ?? config('saas.provisioning.recovery_limit', 25)));
        $dryRun = (bool) $this->option('dry-run');

        $runs = SaasProvisioningRun::query()
            ->whereIn('status', ['pending', 'processing'])
            ->where('updated_at', '<=', now()->subMinutes($staleMinutes))
            ->oldest('updated_at')
            ->limit($limit)
            ->get();

        if ($dryRun) {
            $this->info("[dry-run] {$runs->count()} stale provisioning runs would be resumed.");
            return self::SUCCESS;
        }

        foreach ($runs as $run) {
            $orchestrator->dispatch($run);
        }

        $this->info("Provisioning recovery complete. Resumed {$runs->count()} stale runs.");

        return self::SUCCESS;
    }
}
