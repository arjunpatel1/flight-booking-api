<?php

namespace Modules\Flight\Services\Supplier\DTOs;

class SupplierTicketRequest
{
    public function __construct(
        public string $bookingReference,
        public string $supplierPnr,
        public array $paymentConfirmation = [],
        public array $options = [],
    ) {}
}
