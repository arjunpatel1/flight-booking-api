<?php

namespace Modules\Saas\Services\Provisioning;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Saas\Events\TenantProvisioningStarted;
use Modules\Saas\Models\SaasProvisioningRun;
use Modules\Saas\Support\ProvisioningWorkflow;

class ProvisioningOrchestratorService
{
    public function dispatch(SaasProvisioningRun $run, ?array $onlySteps = null): void
    {
        $lock = Cache::lock("saas:provisioning:dispatch:{$run->id}", 60);

        if (! $lock->get()) {
            Log::info('SaaS provisioning dispatch skipped because another dispatcher holds the run lock.', [
                'run_id' => $run->id,
                'tenant_id' => $run->tenant_id,
            ]);

            return;
        }

        try {
            $run = $run->fresh();

            if (in_array($run->status, ['completed', 'cancelled'], true)) {
                return;
            }

            $steps = $onlySteps ?: ProvisioningWorkflow::pendingRunnableSteps($run->steps ?? []);
            $jobs = collect($steps)
                ->map(fn (string $step) => ProvisioningWorkflow::jobFor($step, $run->id)?->onQueue(ProvisioningWorkflow::queueFor($step)))
                ->filter()
                ->values()
                ->all();

            if (! $jobs) {
                return;
            }

            $run->forceFill([
                'status' => 'processing',
                'started_at' => $run->started_at ?? now(),
                'failed_at' => null,
                'error' => null,
            ])->save();

            event(new TenantProvisioningStarted($run->fresh()));

            Bus::chain($jobs)->dispatch();
        } finally {
            optional($lock)->release();
        }
    }

    public function resume(string $uuid): SaasProvisioningRun
    {
        $run = $this->run($uuid);
        $this->dispatch($run);

        return $run->fresh();
    }

    public function retry(string $uuid, ?string $step = null): SaasProvisioningRun
    {
        $run = $this->run($uuid);
        $failed = collect($run->steps ?? [])
            ->filter(fn (array $payload, string $key) => ($payload['status'] ?? null) === 'failed' && ($step === null || $key === $step))
            ->keys()
            ->values()
            ->all();

        foreach ($failed as $failedStep) {
            $run->incrementStepRetry($failedStep);
        }

        $this->dispatch($run->fresh(), $failed);

        return $run->fresh();
    }

    public function cancel(string $uuid, string $reason = 'Cancelled by admin.'): SaasProvisioningRun
    {
        $run = $this->run($uuid);
        $run->cancel($reason);

        return $run->fresh();
    }

    public function progress(SaasProvisioningRun $run): array
    {
        $definitions = ProvisioningWorkflow::definitions();
        $steps = collect($run->steps ?? []);
        $completed = $steps->filter(fn (array $step) => ($step['status'] ?? null) === 'completed');
        $failed = $steps->filter(fn (array $step) => ($step['status'] ?? null) === 'failed');
        $current = $run->current_step;

        $remainingSeconds = $steps
            ->reject(fn (array $step) => ($step['status'] ?? null) === 'completed')
            ->keys()
            ->sum(fn (string $key) => $definitions[$key]?->estimatedSeconds ?? 5);

        return [
            'current_step' => $current,
            'current_state' => $run->state(),
            'overall_progress' => $run->progress,
            'estimated_remaining_seconds' => $remainingSeconds,
            'current_job' => $current,
            'completed_jobs' => $completed->keys()->values()->all(),
            'failed_jobs' => $failed->keys()->values()->all(),
            'retry_rate' => $steps->sum(fn (array $step) => (int) ($step['retry_count'] ?? 0)),
        ];
    }

    public function metrics(): array
    {
        $runs = SaasProvisioningRun::query()->latest()->limit(100)->get();
        $completed = $runs->where('status', 'completed');
        $durations = $completed
            ->filter(fn (SaasProvisioningRun $run) => $run->started_at && $run->completed_at)
            ->map(fn (SaasProvisioningRun $run) => $run->started_at->diffInSeconds($run->completed_at))
            ->values();

        return [
            'queue_size' => $runs->whereIn('status', ['pending', 'processing'])->count(),
            'average_seconds' => $durations->count() ? round($durations->avg(), 2) : null,
            'fastest_seconds' => $durations->min(),
            'slowest_seconds' => $durations->max(),
            'failure_rate' => $runs->count() ? round($runs->where('status', 'failed')->count() / $runs->count() * 100, 2) : 0,
            'retry_count' => $runs->sum(fn (SaasProvisioningRun $run) => collect($run->steps ?? [])->sum(fn (array $step) => (int) ($step['retry_count'] ?? 0))),
        ];
    }

    private function run(string $uuid): SaasProvisioningRun
    {
        return SaasProvisioningRun::query()
            ->with(['tenant:id,name,slug,domain', 'branch:id,name'])
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
