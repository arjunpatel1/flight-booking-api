<?php

namespace Modules\Flight\Exceptions;

use DomainException;

class FlightTicketingException extends DomainException
{
    public function __construct(
        string $message,
        public ?string $supplierCode = null,
        public ?string $pnr = null,
        int $code = 422
    ) {
        parent::__construct($message, $code);
    }
}
