<?php

namespace Modules\Flight\Services\Supplier\DTOs;

class SupplierHoldRequest
{
    public function __construct(
        public string $offerReference,
        public array $passengers = [],
        public array $contactDetails = [],
        public int $holdDurationMinutes = 30,
        public array $options = [],
    ) {}
}
