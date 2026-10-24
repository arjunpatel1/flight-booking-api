<?php

namespace Modules\Flight\Services\Supplier\DTOs;

class SupplierRefundResponse
{
    public function __construct(
        public bool $success,
        public string $supplierCode,
        public string $bookingReference,
        public string $refundReference,
        public float $refundAmount,
        public float $cancellationPenalty = 0.0,
        public string $currency = 'INR',
        public string $status = 'PROCESSED',
        public ?array $rawResponse = null,
        public ?string $errorMessage = null,
    ) {}
}
