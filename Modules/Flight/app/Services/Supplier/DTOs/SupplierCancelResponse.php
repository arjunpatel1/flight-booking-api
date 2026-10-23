<?php

namespace Modules\Flight\Services\Supplier\DTOs;

class SupplierCancelResponse
{
    public function __construct(
        public bool $success,
        public string $supplierCode,
        public string $bookingReference,
        public string $supplierPnr,
        public bool $isCancelled = true,
        public float $cancellationFee = 0.0,
        public float $refundableAmount = 0.0,
        public string $currency = 'INR',
        public ?array $rawResponse = null,
        public ?string $errorMessage = null,
    ) {}
}
