<?php

namespace Modules\Flight\Services\Supplier\DTOs;

class SupplierReissueResponse
{
    public function __construct(
        public bool $success,
        public string $supplierCode,
        public string $bookingReference,
        public string $supplierPnr,
        public array $newTicketNumbers = [],
        public float $fareDifference = 0.0,
        public float $penaltyFee = 0.0,
        public float $totalCharged = 0.0,
        public string $currency = 'INR',
        public ?array $rawResponse = null,
        public ?string $errorMessage = null,
    ) {}
}
