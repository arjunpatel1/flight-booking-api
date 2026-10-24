<?php

namespace Modules\Flight\Exceptions;

use Exception;

class InvalidPlanException extends Exception
{
    public function __construct(string $planCode, string $message = 'Invalid subscription plan code specified.')
    {
        parent::__construct("{$message} [{$planCode}]", 404);
    }
}
