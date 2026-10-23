<?php

namespace Modules\Flight\Exceptions;

use Exception;

class SubscriptionExpiredException extends Exception
{
    public function __construct(string $message = 'Tenant subscription has expired or is inactive.')
    {
        parent::__construct($message, 403);
    }
}
