<?php

namespace Modules\Flight\Services\Supplier\DTOs;

class SupplierSeatMapResponse
{
    public function __construct(
        public bool $success,
        public string $supplierCode,
        public string $flightReference,
        public array $cabins = [],
        public ?array $rawResponse = null,
        public ?string $errorMessage = null,
    ) {}
}
