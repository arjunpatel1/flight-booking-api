<?php

namespace Modules\Flight\Services\Supplier\Exceptions;

use Exception;
use Throwable;

class SupplierException extends Exception
{
    public function __construct(
        string $message = 'Supplier operation failed',
        protected string $supplierCode = 'UNKNOWN',
        protected ?string $operation = null,
        protected ?array $rawError = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct("[{$supplierCode}] {$message}", $code, $previous);
    }

    public function getSupplierCode(): string
    {
        return $this->supplierCode;
    }

    public function getOperation(): ?string
    {
        return $this->operation;
    }

    public function getRawError(): ?array
    {
        return $this->rawError;
    }
}
