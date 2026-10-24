<?php

namespace Modules\Flight\Enums;

enum FlightWebhookStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSED = 'PROCESSED';
    case FAILED = 'FAILED';
    case IGNORED = 'IGNORED';
}
