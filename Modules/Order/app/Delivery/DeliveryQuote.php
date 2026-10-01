<?php

namespace Modules\Order\Delivery;

use Carbon\CarbonImmutable;

final readonly class DeliveryQuote
{
    public function __construct(
        public string $partnerCode,
        public string $partnerName,
        public float $cost,
        public ?int $etaMinutes,
        public ?string $reference,
        public bool $serviceable = true,
        public ?CarbonImmutable $expiresAt = null,
        public bool $allowsCod = true,
        public bool $allowsPrepaid = true,
        public ?string $unserviceableReason = null,
    ) {}
}
