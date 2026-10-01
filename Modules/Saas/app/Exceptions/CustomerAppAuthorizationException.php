<?php

namespace Modules\Saas\Exceptions;

use RuntimeException;

class CustomerAppAuthorizationException extends RuntimeException
{
    public function __construct(
        public readonly string $machineCode,
        string $message,
        public readonly int $httpStatus = 403,
    ) {
        parent::__construct($message);
    }
}
