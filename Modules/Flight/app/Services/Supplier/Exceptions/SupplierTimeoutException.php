<?php

namespace Modules\Flight\Services\Supplier\Exceptions;

class SupplierTimeoutException extends SupplierException
{
    public function __construct(
        string $supplierCode = 'UNKNOWN',
        ?string $operation = null,
        int $timeoutSeconds = 15,
    ) {
        parent::__construct(
            "Supplier request timed out after {$timeoutSeconds}s during [{$operation}].",
            $supplierCode,
            $operation
        );
    }
}
