<?php

namespace Modules\Order\Delivery;

final readonly class DeliveryBookingResult
{
    public function __construct(
        public bool $successful,
        public ?string $externalDeliveryId = null,
        public ?string $failureCode = null,
        public ?string $failureReason = null,
    ) {}
}
