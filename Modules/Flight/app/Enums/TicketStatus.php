<?php

namespace Modules\Flight\Enums;

enum TicketStatus: string
{
    case PENDING   = 'PENDING';
    case ISSUED    = 'ISSUED';
    case FAILED    = 'FAILED';
    case CANCELLED = 'CANCELLED';
    case VOIDED    = 'VOIDED';
    case REFUNDED  = 'REFUNDED';
    case EXCHANGED = 'EXCHANGED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING   => 'Pending Issuance',
            self::ISSUED    => 'Ticket Issued',
            self::FAILED    => 'Issuance Failed',
            self::CANCELLED => 'Ticket Cancelled',
            self::VOIDED    => 'Ticket Voided',
            self::REFUNDED  => 'Ticket Refunded',
            self::EXCHANGED => 'Ticket Exchanged',
        };
    }
}
