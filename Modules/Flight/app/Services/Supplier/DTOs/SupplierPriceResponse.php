<?php

namespace Modules\Flight\Services\Supplier\DTOs;

class SupplierPriceResponse
{
    public function __construct(
        public bool $success,
        public string $supplierCode,
        public string $offerReference,
        public float $baseFare,
        public float $tax,
        public float $fees,
        public float $totalFare,
        public string $currency = 'INR',
        public bool $isPriceConfirmed = true,
        public float $priceChange = 0.0,
        public array $fareBreakdown = [],
        public ?string $validUntil = null,
        public ?array $rawResponse = null,
        public ?string $errorMessage = null,
    ) {}
}
