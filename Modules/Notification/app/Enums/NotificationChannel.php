<?php

namespace Modules\Notification\Enums;

use Modules\Support\Traits\EnumArrayable;

enum NotificationChannel: string
{
    use EnumArrayable;

    case WhatsApp = "whatsapp";
    case Email = "email";
    case Sms = "sms";
    case Push = "push";
    case InApp = "in_app";
}
