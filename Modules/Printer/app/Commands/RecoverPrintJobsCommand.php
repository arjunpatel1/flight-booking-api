<?php

namespace Modules\Printer\Commands;

use Illuminate\Console\Command;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Models\PrintJob;
use Modules\Printer\Services\Diagnostics\PrintJobTrace;

class RecoverPrintJobsCommand extends Command
{
    protected $signature = 'printer:recover-jobs
        {--lease-grace= : Seconds after lease expiry before the job is reclaimed}
        {--fail-pending-minutes= : Mark very old pending jobs failed; 0 disables}
        {--limit= : Maximum jobs to inspect}
        {--dry-run : Report only without changing jobs}';

    protected $description = 'Recover stale print job leases and optionally fail very old pending jobs.';

    public function handle(): int
    {
        $leaseGrace = max(0, (int) ($this->option('lease-grace') ?? config('printer.queue.stale_lease_grace_seconds', 10)));
        $failPendingMinutes = max(0, (int) ($this->option('fail-pending-minutes') ?? config('printer.queue.fail_stale_pending_minutes', 0)));
        $limit = max(1, (int) ($this->option('limit') ?? config('printer.queue.recovery_limit', 100)));
        $dryRun = (bool) $this->option('dry-run');

        $reclaimed = $this->recoverExpiredLeases($leaseGrace, $limit, $dryRun);
        $failed = $failPendingMinutes > 0
            ? $this->failVeryOldPendingJobs($failPendingMinutes, max(1, $limit - $reclaimed), $dryRun)
            : 0;

        $this->info(($dryRun ? '[dry-run] ' : '')."Print recovery complete. Reclaimed: {$reclaimed}, failed: {$failed}.");

        return self::SUCCESS;
    }

    private function recoverExpiredLeases(int $leaseGrace, int $limit, bool $dryRun): int
    {
        $jobs = PrintJob::query()
            ->where('status', PrintJobStatus::Pending)
            ->whereNotNull('claimed_by')
            ->whereNotNull('lease_until')
            ->where('lease_until', '<=', now()->subSeconds($leaseGrace))
            ->oldest('lease_until')
            ->limit($limit)
            ->get();

        if ($dryRun) {
            return $jobs->count();
        }

        foreach ($jobs as $job) {
            PrintJobTrace::appendToJob($job, 'lease_recovered', 'Expired printer lease was released for agent pickup.', [
                'previous_agent_id' => $job->claimed_by,
                'lease_until' => optional($job->lease_until)->toIso8601String(),
            ]);

            $job->forceFill([
                'claimed_by' => null,
                'lease_until' => null,
                'error_message' => null,
            ])->saveQuietly();
        }

        return $jobs->count();
    }

    private function failVeryOldPendingJobs(int $minutes, int $limit, bool $dryRun): int
    {
        $jobs = PrintJob::query()
            ->where('status', PrintJobStatus::Pending)
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->oldest('created_at')
            ->limit($limit)
            ->get();

        if ($dryRun) {
            return $jobs->count();
        }

        foreach ($jobs as $job) {
            $message = "Print job stayed pending for more than {$minutes} minutes.";
            PrintJobTrace::appendToJob($job, 'failed', $message, [
                'stale_minutes' => $minutes,
            ]);

            $job->forceFill([
                'status' => PrintJobStatus::Failed,
                'claimed_by' => null,
                'lease_until' => null,
                'error_message' => $message,
                'completed_at' => now(),
            ])->saveQuietly();
        }

        return $jobs->count();
    }
}
