<?php

namespace Modules\Flight\Enums;

enum SettlementMismatchSeverity: string
{
    case LOW      = 'LOW';
    case MEDIUM   = 'MEDIUM';
    case HIGH     = 'HIGH';
    case CRITICAL = 'CRITICAL';
}
