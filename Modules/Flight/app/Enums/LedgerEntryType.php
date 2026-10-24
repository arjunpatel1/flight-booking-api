<?php

namespace Modules\Flight\Enums;

enum LedgerEntryType: string
{
    case DEBIT = 'DEBIT';
    case CREDIT = 'CREDIT';
}
