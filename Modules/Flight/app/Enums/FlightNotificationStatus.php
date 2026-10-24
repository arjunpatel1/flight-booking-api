<?php

namespace Modules\Flight\Enums;

enum FlightNotificationStatus: string
{
    case PENDING = 'PENDING';
    case QUEUED = 'QUEUED';
    case SENT = 'SENT';
    case DELIVERED = 'DELIVERED';
    case FAILED = 'FAILED';
    case RETRYING = 'RETRYING';
}
