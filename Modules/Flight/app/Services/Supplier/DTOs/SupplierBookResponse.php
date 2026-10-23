<?php

namespace Modules\Flight\Services\Supplier\DTOs;

class SupplierBookResponse
{
    public function __construct(
        public bool $success,
        public string $supplierCode,
        public string $bookingReference,
        public string $supplierPnr,
        public string $status = 'CONFIRMED',
        public float $totalAmount = 0.0,
        public string $currency = 'INR',
        public ?string $ticketingTimeLimit = null,
        public array $passengers = [],
        public ?array $rawResponse = null,
        public ?string $errorMessage = null,
    ) {}
}
