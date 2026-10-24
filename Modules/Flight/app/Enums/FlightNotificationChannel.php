<?php

namespace Modules\Flight\Enums;

enum FlightNotificationChannel: string
{
    case EMAIL = 'email';
    case SMS = 'sms';
    case WHATSAPP = 'whatsapp';
}
