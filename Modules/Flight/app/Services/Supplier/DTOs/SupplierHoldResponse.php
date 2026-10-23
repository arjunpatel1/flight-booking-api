<?php

namespace Modules\Flight\Services\Supplier\DTOs;

class SupplierHoldResponse
{
    public function __construct(
        public bool $success,
        public string $supplierCode,
        public string $holdReference,
        public ?string $supplierPnr = null,
        public ?string $heldUntil = null,
        public ?float $guaranteedPrice = null,
        public string $currency = 'INR',
        public ?array $rawResponse = null,
        public ?string $errorMessage = null,
    ) {}
}
