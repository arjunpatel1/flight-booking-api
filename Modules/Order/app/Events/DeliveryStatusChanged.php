<?php

namespace Modules\Order\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Modules\Order\Enums\DeliveryStatus;

final class DeliveryStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $deliveryId,
        public readonly int $orderId,
        public readonly DeliveryStatus $from,
        public readonly DeliveryStatus $to,
    ) {}
}
