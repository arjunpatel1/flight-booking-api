<?php

namespace Modules\Flight\Services\Supplier\DTOs;

class SupplierBookRequest
{
    public function __construct(
        public string $offerReference,
        public ?string $holdReference = null,
        public array $passengers = [],
        public ?array $paymentDetails = null,
        public array $options = [],
    ) {}
}
