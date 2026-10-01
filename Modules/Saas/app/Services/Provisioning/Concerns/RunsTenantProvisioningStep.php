<?php

namespace Modules\Saas\Services\Provisioning\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Saas\Models\SaasProvisioningRun;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Support\TenantContext;
use Throwable;

trait RunsTenantProvisioningStep
{
    protected function runStep(int $runId, string $step, callable $callback): void
    {
        $lock = Cache::lock("saas:provisioning:step:{$runId}:{$step}", 600);

        if (! $lock->get()) {
            Log::info('SaaS provisioning step skipped because another worker holds the step lock.', [
                'run_id' => $runId,
                'step' => $step,
            ]);

            return;
        }

        try {
            $run = SaasProvisioningRun::query()->findOrFail($runId);
            if (in_array($run->status, ['cancelled', 'completed'], true)) {
                return;
            }

            if (($run->steps[$step]['status'] ?? null) === 'completed') {
                return;
            }

            $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($run->tenant_id);

            app(TenantContext::class)->set($tenant);
            $run->markStep($step, 'processing');

            try {
                $callback($run, $tenant);
                $run->markStep($step, 'completed');
            } catch (Throwable $exception) {
                $run->markStep($step, 'failed', $exception->getMessage());

                Log::error('SaaS provisioning step failed.', [
                    'run_id' => $run->id,
                    'tenant_id' => $tenant->id,
                    'step' => $step,
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }
        } finally {
            app(TenantContext::class)->clear();
            optional($lock)->release();
        }
    }
}
