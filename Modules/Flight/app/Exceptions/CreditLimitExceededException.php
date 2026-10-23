<?php

namespace Modules\Flight\Exceptions;

use Exception;

class CreditLimitExceededException extends Exception
{
    public function __construct(string $message = 'Proposed credit limit adjustment violates active utilization constraints.')
    {
        parent::__construct($message, 422);
    }
}
