<?php

namespace Modules\Notification\Enums;

use Modules\Support\Traits\EnumArrayable;

enum WhatsAppProvider: string
{
    use EnumArrayable;

    case Msg91 = "msg91";
    case NexMsg = "nexmsg";
    case Meta = "meta";
    case Twilio = "twilio";

    public function trans(): string
    {
        return __("notification::notifications.whatsapp_providers.{$this->value}");
    }
}
