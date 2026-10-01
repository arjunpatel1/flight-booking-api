<?php

namespace Modules\Support\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum MailEncryptionProtocol: string
{
    use EnumArrayable, EnumTranslatable;

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "support::enums.mail_encryption_protocols";
    }

    case SSL = 'ssl';
    case Tls = 'tls';
}
