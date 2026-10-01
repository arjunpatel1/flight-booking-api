<?php

namespace Modules\Aggregator\DTO;

use Modules\Order\Models\Order;

class AggregatorOrderPayload
{
    public static function fromOrder(Order $order, string $event): array
    {
        return [
            'version' => 'internal.v1',
            'event' => $event,
            'order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'reference_no' => $order->reference_no,
            'status' => $order->status?->value,
            'type' => $order->type?->value,
            'total' => $order->total,
            'currency' => $order->currency,
            'order_date' => $order->order_date,
        ];
    }
}
