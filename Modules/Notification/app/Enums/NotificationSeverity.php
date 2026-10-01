<?php

namespace Modules\Notification\Enums;

use Modules\Support\Traits\EnumArrayable;

enum NotificationSeverity: string
{
    use EnumArrayable;

    case Success = "success";
    case Warning = "warning";
    case Error = "error";
    case Info = "info";

    public function color(): string
    {
        return match ($this) {
            self::Success => "success",
            self::Warning => "warning",
            self::Error => "error",
            self::Info => "info",
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Success => "tabler-circle-check",
            self::Warning => "tabler-alert-triangle",
            self::Error => "tabler-circle-x",
            self::Info => "tabler-info-circle",
        };
    }
}
