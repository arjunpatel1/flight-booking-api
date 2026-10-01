<?php

namespace Modules\Order\Events\Concerns;

use Illuminate\Broadcasting\PrivateChannel;
use Modules\Core\Events\PlatformEvent;
use Modules\Order\Enums\OrderStatus;

trait BroadcastsOrderRealtime
{
    public bool $afterCommit = true;

    public int $tries = 10;

    public int $backoff = 5;

    public function broadcastOn(): array
    {
        $branchId = $this->order->branch_id;

        return [
            new PrivateChannel("pos.orders.branch.{$branchId}"),
            new PrivateChannel("pos.kitchen.branch.{$branchId}"),
            new PrivateChannel("pos.tables.branch.{$branchId}"),
        ];
    }

    public function broadcastWith(): array
    {
        $status = property_exists($this, 'status') && $this->status instanceof OrderStatus
            ? $this->status
            : $this->order->status;

        $order = [
            'id' => $this->order->id,
            'reference_no' => $this->order->reference_no,
            'order_number' => $this->order->order_number,
            'branch_id' => $this->order->branch_id,
            'table_id' => $this->order->table_id,
            'waiter_id' => $this->order->waiter_id,
            'status' => $status?->value,
            'type' => $this->order->type?->value,
            'payment_status' => $this->order->payment_status?->value,
            'updated_at' => $this->order->updated_at?->toISOString(),
        ];

        return [
            // Canonical platform envelope (event_id, event_name, entity, branch_id,
            // tenant_id, timestamp, version, source, payload).
            ...PlatformEvent::envelope(
                eventName: $this->broadcastAs(),
                entity: 'order',
                entityId: $this->order->id,
                branchId: $this->order->branch_id,
                payload: ['order' => $order],
            ),
            // Legacy top-level keys retained for current consumers (dropped in a
            // later slice once Vue/Flutter read from `payload`).
            'order' => $order,
            'branch_id' => $this->order->branch_id,
            'table_id' => $this->order->table_id,
            'waiter_id' => $this->order->waiter_id,
        ];
    }
}
