<?php

namespace Modules\Media\Enum;

use Modules\Support\Traits\EnumArrayable;

enum MediaType: string
{
    use EnumArrayable;
    
    case File = 'file';
    case Folder = 'folder';
}
