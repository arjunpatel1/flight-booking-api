<?php

namespace Modules\Saas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Saas\Models\SaasProvisioningRun;
use Modules\Saas\Services\Workspace\TenantWelcomeNotifier;

class CompleteTenantProvisioningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    public function __construct(public readonly int $runId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $run = SaasProvisioningRun::query()->findOrFail($this->runId);
        if (in_array($run->status, ['cancelled', 'failed'], true)) {
            return;
        }

        $failedSteps = collect($run->steps ?? [])->where('status', 'failed');
        if ($failedSteps->isNotEmpty()) {
            $run->markPartial('Provisioning finished with failed steps.');
            return;
        }

        $run->complete();

        // Welcome email / WhatsApp.
        //
        // Provisioning creates the tenant already active rather than routing
        // through SaasTenantLifecycleService::activate(), so hooking the
        // notifier there alone meant it only ever fired when an operator
        // manually re-activated a suspended restaurant — never on the actual
        // onboarding path. This is that path's completion point.
        //
        // Both channels remain off by default and the notifier swallows
        // delivery failures, so a provisioning run never fails on a message.
        if ($run->tenant) {
            app(TenantWelcomeNotifier::class)->send($run->tenant);
        }
    }
}
