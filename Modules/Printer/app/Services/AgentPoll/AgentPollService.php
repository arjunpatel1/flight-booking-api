<?php

namespace Modules\Printer\Services\AgentPoll;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Models\PrintJob;
use Modules\Printer\Services\Diagnostics\PrintJobTrace;

class AgentPollService implements AgentPollServiceInterface
{
    /** {@inheritDoc} */
    public function poll(PrintAgent $agent, int $branchId): Collection
    {
        $startedAt = microtime(true);

        throw_if($branchId !== $agent->branch_id, new Exception('Branch mismatch'));

        $leaseSeconds = max((int) config('printer.queue.delivery_lease_seconds', 120), 30);
        $batchSize = max(1, min((int) config('printer.queue.agent_batch_size', 10), 25));
        $heartbeatSeconds = max((int) config('printer.queue.agent_heartbeat_seconds', 30), 5);
        $emptyPollCacheSeconds = max((int) config('printer.queue.empty_poll_cache_seconds', 10), 1);
        $pollingFallbackDelaySeconds = strtolower((string) config('printer.agent.transport', 'polling')) === 'polling'
            ? 0
            : max((int) config('printer.queue.polling_fallback_delay_seconds', 8), 0);
        $now = now();
        $emptyPollCacheKey = self::emptyPollCacheKey($agent->branch_id, $agent->agent_id);

        if (Cache::get($emptyPollCacheKey) && ! $this->hasRetryableLeasedJob($agent, $now)) {
            return collect();
        }

        // Update heartbeat outside the transaction: the lockForUpdate on print_jobs
        // must not be held while an unrelated row write on print_agents completes.
        if (is_null($agent->last_seen_at) || $agent->last_seen_at->lte($now->copy()->subSeconds($heartbeatSeconds))) {
            $agent->forceFill(['last_seen_at' => $now])->saveQuietly();
        }

        return DB::transaction(function () use ($agent, $leaseSeconds, $batchSize, $emptyPollCacheSeconds, $emptyPollCacheKey, $pollingFallbackDelaySeconds, $now, $startedAt) {
            $jobs = PrintJob::query()
                ->where('branch_id', $agent->branch_id)
                ->where('status', PrintJobStatus::Pending)
                ->where(function ($query) use ($now) {
                    $query->whereNull('claimed_by')
                        ->orWhereNull('lease_until')
                        ->orWhere('lease_until', '<=', $now);
                })
                ->where(function ($query) use ($agent) {
                    $query->where('printer_config->agent_id', $agent->agent_id)
                        ->orWhereNull('printer_config->agent_id')
                        ->orWhere('printer_config->agent_id', '');
                })
                ->where('printer_config->provider_type', $this->providerTypeFor($agent))
                ->when($pollingFallbackDelaySeconds > 0, function ($query) use ($agent, $now, $pollingFallbackDelaySeconds) {
                    $query->where(function ($query) use ($agent, $now, $pollingFallbackDelaySeconds) {
                        $query->whereNull('printer_config->agent_id')
                            ->orWhere('printer_config->agent_id', '')
                            ->orWhere('printer_config->agent_id', '!=', $agent->agent_id)
                            ->orWhere('created_at', '<=', $now->copy()->subSeconds($pollingFallbackDelaySeconds));
                    });
                })
                ->orderBy('created_at')
                ->lockForUpdate()
                ->take($batchSize)
                ->get();

            if ($jobs->isEmpty()) {
                Cache::put($emptyPollCacheKey, true, $emptyPollCacheSeconds);

                return collect();
            }

            Cache::forget($emptyPollCacheKey);

            PrintJob::query()
                ->whereIn('id', $jobs->pluck('id'))
                ->update([
                    'claimed_by' => $agent->agent_id,
                    'lease_until' => $now->copy()->addSeconds($leaseSeconds),
                ]);

            $jobs->each(fn (PrintJob $job) => PrintJobTrace::appendToJob(
                $job,
                'claimed',
                'Agent picked up this print job.',
                [
                    'agent_id' => $agent->agent_id,
                    'lease_until' => $now->copy()->addSeconds($leaseSeconds)->toISOString(),
                    'claim_source' => 'poll',
                ],
            ));

            Log::info('Print agent claimed pending jobs.', [
                'agent_id' => $agent->agent_id,
                'branch_id' => $agent->branch_id,
                'job_ids' => $jobs->pluck('id')->values()->all(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'polling_fallback_delay_seconds' => $pollingFallbackDelaySeconds,
                'unassigned_job_ids' => $jobs
                    ->filter(fn (PrintJob $job) => blank(data_get($job->printer_config, 'agent_id')))
                    ->pluck('id')
                    ->values()
                    ->all(),
            ]);

            return $jobs->map(fn (PrintJob $job) => $this->formatJob($job));
        });
    }

    /** {@inheritDoc} */
    public function fetchJob(PrintAgent $agent, string $jobId, int $branchId): array
    {
        $startedAt = microtime(true);

        throw_if($branchId !== $agent->branch_id, new Exception('Branch mismatch'));

        $leaseSeconds = max((int) config('printer.queue.delivery_lease_seconds', 120), 30);
        $heartbeatSeconds = max((int) config('printer.queue.agent_heartbeat_seconds', 30), 5);
        $now = now();

        if (is_null($agent->last_seen_at) || $agent->last_seen_at->lte($now->copy()->subSeconds($heartbeatSeconds))) {
            $agent->forceFill(['last_seen_at' => $now])->saveQuietly();
        }

        return DB::transaction(function () use ($agent, $jobId, $leaseSeconds, $now, $startedAt) {
            $job = PrintJob::query()
                ->where('id', $jobId)
                ->where('branch_id', $agent->branch_id)
                ->lockForUpdate()
                ->first();

            throw_if(! $job, new Exception('Job not found'));
            throw_if($job->status !== PrintJobStatus::Pending, new Exception('Job is not pending'));
            throw_if(
                data_get($job->printer_config, 'provider_type') !== $this->providerTypeFor($agent),
                new Exception('Job is assigned to an incompatible agent platform')
            );
            throw_if(
                filled(data_get($job->printer_config, 'agent_id')) && data_get($job->printer_config, 'agent_id') !== $agent->agent_id,
                new Exception('Job is assigned to another agent')
            );
            if (filled($job->claimed_by)) {
                throw_if(
                    $job->claimed_by !== $agent->agent_id,
                    new Exception('Job is already leased')
                );
            }

            $job->update([
                'claimed_by' => $agent->agent_id,
                'lease_until' => $now->copy()->addSeconds($leaseSeconds),
            ]);

            PrintJobTrace::appendToJob(
                $job,
                'claimed',
                'Agent fetched this print job directly.',
                [
                    'agent_id' => $agent->agent_id,
                    'lease_until' => $now->copy()->addSeconds($leaseSeconds)->toISOString(),
                    'claim_source' => 'fetch',
                ],
            );

            Cache::forget(self::emptyPollCacheKey($agent->branch_id, $agent->agent_id));

            Log::info('Print job lease assigned to agent.', [
                'agent_id' => $agent->agent_id,
                'branch_id' => $agent->branch_id,
                'job_id' => $job->id,
                'printer_type' => data_get($job->printer_config, 'type'),
                'printer_agent_id' => data_get($job->printer_config, 'agent_id'),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return $this->formatJob($job);
        });
    }

    private function formatJob(PrintJob $job): array
    {
        return [
            'job_id' => $job->id,
            'branch_id' => $job->branch_id,
            'printer' => $job->printer_config,
            'rendered_bytes' => $job->rendered_bytes,
        ];
    }

    private function hasRetryableLeasedJob(PrintAgent $agent, $now): bool
    {
        return PrintJob::query()
            ->where('branch_id', $agent->branch_id)
            ->where('status', PrintJobStatus::Pending)
            ->whereNotNull('claimed_by')
            ->where(function ($query) use ($now) {
                $query->whereNull('lease_until')
                    ->orWhere('lease_until', '<=', $now);
            })
            ->where(function ($query) use ($agent) {
                $query->where('printer_config->agent_id', $agent->agent_id)
                    ->orWhereNull('printer_config->agent_id')
                    ->orWhere('printer_config->agent_id', '');
            })
            ->where('printer_config->provider_type', $this->providerTypeFor($agent))
            ->exists();
    }

    private function providerTypeFor(PrintAgent $agent): string
    {
        return match (strtolower((string) $agent->platform)) {
            'android' => 'android_app',
            'linux', 'ubuntu' => 'ubuntu_agent',
            default => 'windows_agent',
        };
    }

    public static function emptyPollCacheKey(int $branchId, string $agentId): string
    {
        return "printer-agent-empty-poll:{$branchId}:{$agentId}";
    }

    /** {@inheritDoc} */
    public function report(PrintAgent $agent, string $jobId, PrintJobStatus $status, ?string $error = null): void
    {
        $startedAt = microtime(true);

        $agent->forceFill(['last_seen_at' => now()])->saveQuietly();

        $job = PrintJob::query()->find($jobId);

        throw_if(! $job || $job->branch_id !== $agent->branch_id, new Exception('Job not found'));
        throw_if($job->claimed_by && $job->claimed_by !== $agent->agent_id, new Exception('Job is assigned to another agent'));

        $job->update([
            'status' => $status,
            'error_message' => $error,
            'lease_until' => null,
            'completed_at' => now(),
        ]);

        PrintJobTrace::appendToJob(
            $job,
            $status === PrintJobStatus::Success ? 'success' : 'failed',
            $status === PrintJobStatus::Success
                ? 'Agent reported successful print.'
                : ($error ?: 'Agent reported print failure.'),
            [
                'agent_id' => $agent->agent_id,
                'reported_status' => $status->value,
                'error' => $error,
            ],
        );

        Log::info('Print job status updated from agent report.', [
            'agent_id' => $agent->agent_id,
            'branch_id' => $agent->branch_id,
            'job_id' => $job->id,
            'status' => $status->value,
            'error' => $error,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
