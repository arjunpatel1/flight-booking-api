<?php

namespace Modules\Aggregator\Services\Providers;

class ZomatoContract
{
    public static function statusEndpointKey(?string $status): ?string
    {
        return match ($status) {
            'confirmed' => 'confirm',
            'cancelled' => 'reject',
            'ready' => 'ready',
            'served' => 'picked_up',
            'completed' => 'delivered',
            default => null,
        };
    }

    public static function statusEndpointDefaults(): array
    {
        return [
            'confirm' => self::endpoint('/online-ordering/v1/order/confirm'),
            'reject' => self::endpoint('/online-ordering/v1/order/reject'),
            'ready' => self::endpoint('/online-ordering/v1/order/ready'),
            'picked_up' => self::endpoint('/online-ordering/v1/order/pickedup'),
            'assigned' => self::endpoint('/online-ordering/v1/order/assigned'),
            'delivered' => self::endpoint('/online-ordering/v1/order/delivered'),
        ];
    }

    private static function endpoint(string $url): array
    {
        return [
            'enabled' => false,
            'method' => 'POST',
            'url' => $url,
            'headers' => [],
            'body' => [
                'order_id' => '{{external_order_id}}',
            ],
            'timeout' => 30,
        ];
    }
}
