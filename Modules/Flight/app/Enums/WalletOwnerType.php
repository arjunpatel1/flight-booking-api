<?php

namespace Modules\Flight\Enums;

enum WalletOwnerType: string
{
    case TENANT = 'TENANT';
    case DISTRIBUTOR = 'DISTRIBUTOR';
    case AGENT = 'AGENT';
    case SYSTEM = 'SYSTEM';
}
