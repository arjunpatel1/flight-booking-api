<?php

namespace Modules\Notification\Enums;

use Modules\Support\Traits\EnumArrayable;

enum NotificationStatus: string
{
    use EnumArrayable;

    case Pending = "pending";
    case Processing = "processing";
    case Sent = "sent";
    case Failed = "failed";
    case Skipped = "skipped";
}
