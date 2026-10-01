<?php

namespace Modules\WhatsAppCenter\Exceptions;

use RuntimeException;

class WhatsAppContextException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, int $status = 404)
    {
        parent::__construct('The WhatsApp integration is unavailable.', $status);
    }
}
