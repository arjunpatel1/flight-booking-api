<?php

namespace Modules\Core\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'core:prune-idempotency
        {--days= : Completed request retention in days}
        {--processing-hours= : Stale processing request retention in hours}
        {--batch= : Maximum rows deleted per query}';

    protected $description = 'Prune expired idempotency responses in bounded batches.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('core.idempotency.completed_retention_days', 30));
        $processingHours = (int) ($this->option('processing-hours') ?: config('core.idempotency.processing_retention_hours', 24));
        $batchSize = (int) ($this->option('batch') ?: config('core.idempotency.prune_batch_size', 1000));

        if ($days < 1 || $processingHours < 1 || $batchSize < 1 || $batchSize > 10000) {
            $this->error('Retention values must be positive and batch size must not exceed 10000.');
            return self::FAILURE;
        }

        $completedCutoff = now()->subDays($days);
        $processingCutoff = now()->subHours($processingHours);

        $completed = $this->deleteInBatches(
            fn (): Builder => DB::table('idempotency_keys')
                ->where('status', 'completed')
                ->where('completed_at', '<', $completedCutoff),
            $batchSize
        );

        $processing = $this->deleteInBatches(
            fn (): Builder => DB::table('idempotency_keys')
                ->where('status', 'processing')
                ->where(function (Builder $query) use ($processingCutoff): void {
                    $query->where('locked_until', '<', $processingCutoff)
                        ->orWhere(function (Builder $query) use ($processingCutoff): void {
                            $query->whereNull('locked_until')
                                ->where('created_at', '<', $processingCutoff);
                        });
                }),
            $batchSize
        );

        $this->info("Pruned {$completed} completed and {$processing} stale processing idempotency records.");

        return self::SUCCESS;
    }

    /** @param Closure(): Builder $query */
    private function deleteInBatches(Closure $query, int $batchSize): int
    {
        $total = 0;

        do {
            $ids = $query()
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted = DB::table('idempotency_keys')
                ->whereIn('id', $ids)
                ->delete();
            $total += $deleted;
        } while ($ids->count() === $batchSize);

        return $total;
    }
}
