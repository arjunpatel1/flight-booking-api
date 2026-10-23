<?php

namespace Modules\Flight\Services\Supplier\DTOs;

use Modules\Flight\Enums\SupplierHealthStatus;

class SupplierHealthResult
{
    public function __construct(
        public string $supplierCode,
        public SupplierHealthStatus $status,
        public int $latencyMs = 0,
        public ?string $endpoint = null,
        public bool $isHealthy = true,
        public ?string $errorMessage = null,
        public array $details = [],
    ) {}
}
