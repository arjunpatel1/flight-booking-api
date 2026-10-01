<?php

namespace Modules\Aggregator\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Aggregator\Services\MenuSync\AggregatorMenuSyncServiceInterface;
use Modules\Aggregator\Services\OrderSync\AggregatorOrderSyncServiceInterface;
use Modules\Aggregator\Services\StatusSync\AggregatorStatusSyncServiceInterface;

class SyncAggregatorIntegrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $integrationId,
        public string $type,
        public array $payload = [],
    ) {
        $this->onQueue(match ($type) {
            'menu' => 'aggregator-menu',
            'status' => 'aggregator-status',
            'order' => 'aggregator-sync',
            default => 'aggregator-sync',
        });
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        match ($this->type) {
            'menu' => app(AggregatorMenuSyncServiceInterface::class)->sync($this->integrationId, $this->payload),
            'order' => app(AggregatorOrderSyncServiceInterface::class)->sync($this->integrationId, $this->payload),
            'status' => app(AggregatorStatusSyncServiceInterface::class)->sync($this->integrationId, $this->payload),
            default => app(AggregatorMenuSyncServiceInterface::class)->sync($this->integrationId, $this->payload),
        };
    }
}
