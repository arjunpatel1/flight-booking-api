<?php

namespace Modules\Flight\Exceptions;

use Exception;

class WalletInactiveException extends Exception
{
    public function __construct(string $message = 'The target wallet is currently suspended or inactive.')
    {
        parent::__construct($message, 403);
    }
}
