<?php

namespace Modules\Order\Delivery;

use Modules\Order\Enums\DeliveryStatus;

final class UengageStatus
{
    public static function normalize(mixed $status): ?DeliveryStatus
    {
        if (! is_string($status)) {
            return null;
        }

        $normalized = strtoupper(trim($status));

        if ($normalized === '' || strlen($normalized) > 80) {
            return null;
        }

        return match ($normalized) {
            'ACCEPTED', 'SEARCHING_FOR_NEW_RIDER' => DeliveryStatus::RiderSearching,
            'ALLOTTED' => DeliveryStatus::RiderAssigned,
            'ARRIVED' => DeliveryStatus::ArrivedAtPickup,
            'DISPATCHED' => DeliveryStatus::PickedUp,
            'ARRIVED_CUSTOMER_DOORSTEP' => DeliveryStatus::ArrivedAtCustomer,
            'DELIVERED' => DeliveryStatus::Delivered,
            'CANCELLED' => DeliveryStatus::Cancelled,
            'RTO_INIT' => DeliveryStatus::Rto,
            'RTO_COMPLETE' => DeliveryStatus::RtoCompleted,
            'RETURNED_AFTER_DELIVERY' => DeliveryStatus::ReturnedAfterDelivery,
            default => null,
        };
    }
}
