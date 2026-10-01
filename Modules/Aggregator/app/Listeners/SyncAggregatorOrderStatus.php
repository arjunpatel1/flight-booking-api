<?php

namespace Modules\Aggregator\Listeners;

use Modules\Aggregator\DTO\AggregatorOrderPayload;
use Modules\Aggregator\Jobs\AggregatorStatusSyncJob;
use Modules\Aggregator\Models\AggregatorIntegration;
use Modules\Aggregator\Models\AggregatorOrderMapping;
use Modules\Order\Events\OrderUpdateStatus;

class SyncAggregatorOrderStatus
{
    public function handle(OrderUpdateStatus $event): void
    {
        $payload = [
            ...AggregatorOrderPayload::fromOrder($event->order, 'order_status_updated'),
            'status' => $event->status->value,
            'reason_id' => $event->reasonId,
            'changed_by_id' => $event->changedById,
            'note' => $event->note,
        ];

        $mappedIntegrationIds = AggregatorOrderMapping::query()
            ->where('order_id', $event->order->id)
            ->pluck('aggregator_integration_id');

        AggregatorIntegration::query()
            ->where('is_active', true)
            ->when(
                $mappedIntegrationIds->isNotEmpty(),
                fn($query) => $query->whereIn('id', $mappedIntegrationIds),
                fn($query) => $query->where('settings->sync_direct_orders', true)
            )
            ->whereHas('outletMappings', fn($query) => $query
                ->where('branch_id', $event->order->branch_id)
                ->where('is_active', true))
            ->get()
            ->each(fn(AggregatorIntegration $integration) => AggregatorStatusSyncJob::dispatch($integration->id, $payload));
    }
}
