<?php

namespace Modules\Saas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Saas\Models\SaasDeploymentRun;
use Modules\Saas\Services\Infrastructure\SaasDeploymentService;
use Throwable;

class RunSaasDeploymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 960;

    public function __construct(public readonly int $runId)
    {
        $this->afterCommit();
    }

    public function handle(SaasDeploymentService $service): void
    {
        $run = SaasDeploymentRun::query()->findOrFail($this->runId);
        $run->forceFill(['status' => 'processing', 'started_at' => now()])->save();

        try {
            $result = $service->execute($run);
            $timestamps = $result['successful']
                ? ['completed_at' => now()]
                : ['failed_at' => now()];
            $run->forceFill([
                'status' => $result['successful'] ? 'completed' : 'failed',
                'output' => $result['output'],
                'error' => $result['error'] ?: null,
                'previous_revision' => $result['previous_revision'],
                'new_revision' => $result['new_revision'],
                ...$timestamps,
            ])->save();
        } catch (Throwable $exception) {
            $run->forceFill(['status' => 'failed', 'error' => $exception->getMessage(), 'failed_at' => now()])->save();
            throw $exception;
        }
    }
}
