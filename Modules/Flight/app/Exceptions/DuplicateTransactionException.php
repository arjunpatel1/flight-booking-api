<?php

namespace Modules\Flight\Exceptions;

use Exception;

class DuplicateTransactionException extends Exception
{
    public function __construct(
        public readonly string $reference,
        string $message = 'A financial transaction with this reference has already been processed.'
    ) {
        parent::__construct($message . " (Reference: {$reference})", 409);
    }
}
