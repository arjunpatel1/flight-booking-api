<?php

namespace Modules\Support\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum FilesystemDisk: string
{
    use EnumArrayable, EnumTranslatable;

    case Public = "public";
    case S3 = "s3";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "support::enums.filesystem_disks";
    }
}
