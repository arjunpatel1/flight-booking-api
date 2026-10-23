<?php

namespace Modules\Flight\Services\Supplier\DTOs;

class SupplierAvailabilityResponse
{
    public function __construct(
        public bool $success,
        public string $supplierCode,
        public string $offerReference,
        public bool $isAvailable,
        public int $availableSeats = 0,
        public string $cabinClass = 'ECONOMY',
        public ?float $confirmedPrice = null,
        public string $currency = 'INR',
        public ?array $rawResponse = null,
        public ?string $errorMessage = null,
    ) {}
}
