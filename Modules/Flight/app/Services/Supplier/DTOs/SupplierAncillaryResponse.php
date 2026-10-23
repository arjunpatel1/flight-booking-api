<?php

namespace Modules\Flight\Services\Supplier\DTOs;

class SupplierAncillaryResponse
{
    public function __construct(
        public bool $success,
        public string $supplierCode,
        public string $flightReference,
        public array $baggageOptions = [],
        public array $mealOptions = [],
        public array $seatOptions = [],
        public array $loungeOptions = [],
        public ?array $rawResponse = null,
        public ?string $errorMessage = null,
    ) {}
}
