<?php

namespace Modules\Flight\Services\Supplier\DTOs;

class SupplierTicketResponse
{
    public function __construct(
        public bool $success,
        public string $supplierCode,
        public string $bookingReference,
        public string $supplierPnr,
        public array $ticketNumbers = [],
        public string $status = 'ISSUED',
        public ?string $issuedAt = null,
        public ?array $rawResponse = null,
        public ?string $errorMessage = null,
    ) {
        $this->issuedAt ??= now()->toIso8601String();
    }
}
