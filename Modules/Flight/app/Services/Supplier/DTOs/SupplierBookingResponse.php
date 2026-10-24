<?php

namespace Modules\Flight\Services\Supplier\DTOs;

class SupplierBookingResponse
{
    public function __construct(
        public bool $success,
        public string $supplierCode,
        public string $bookingReference,
        public string $supplierPnr,
        public string $status = 'CONFIRMED',
        public array $passengers = [],
        public array $itinerary = [],
        public array $tickets = [],
        public ?array $rawResponse = null,
        public ?string $errorMessage = null,
    ) {}
}
