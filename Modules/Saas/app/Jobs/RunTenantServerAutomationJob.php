<?php

namespace Modules\Saas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Saas\Models\SaasDeliveryJob;
use Modules\Saas\Services\Provisioning\SaasServerAutomationService;
use Throwable;

class RunTenantServerAutomationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 360;

    public function __construct(public readonly int $deliveryJobId)
    {
        $this->afterCommit();
    }

    public function handle(SaasServerAutomationService $service): void
    {
        $job = SaasDeliveryJob::query()->findOrFail($this->deliveryJobId);
        $job->processing();

        try {
            $result = $service->configureWebServerSsl($job->payload ?? []);
            $result['successful']
                ? $job->complete($result)
                : $job->failWith($result['error_output'] ?: 'Server automation failed.');
        } catch (Throwable $exception) {
            $job->failWith($exception->getMessage());
            throw $exception;
        }
    }
}
