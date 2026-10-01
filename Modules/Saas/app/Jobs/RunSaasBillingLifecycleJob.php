<?php

namespace Modules\Saas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Saas\Services\Billing\SaasLicenseLifecycleService;

class RunSaasBillingLifecycleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct()
    {
        $this->onQueue(config('saas.queues.monitoring', 'monitoring'));
    }

    public function handle(SaasLicenseLifecycleService $licenses): void
    {
        $result = $licenses->runLifecycle();

        Log::info('SaaS billing lifecycle job completed.', [
            'subscriptions_processed' => count($result['processed'] ?? []),
            'dunning_processed' => count($result['dunning'] ?? []),
        ]);
    }
}
