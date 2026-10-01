<?php

namespace Modules\Setting\Enums;

use Modules\Support\Traits\EnumArrayable;

enum SettingSection: string
{
    use EnumArrayable;

    case General = "general";
    case Application = "application";
    case Currency = "currency";
    case Mail = "mail";
    case Filesystem = "filesystem";
    case Logo = "logo";
    case Kitchen = "kitchen";
    case WhatsApp = "whatsapp";
    case Notifications = "notifications";
    case CustomerApp = "customer_app";
    case Delivery = "delivery";
    case Firebase = "firebase";
    case Analytics = "analytics";
    case Appearance = "appearance";
    case SystemConfiguration = "system_configuration";
    case Gst = "gst";

}
