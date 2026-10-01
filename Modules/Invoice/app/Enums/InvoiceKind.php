<?php

namespace Modules\Invoice\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum InvoiceKind: string
{
    use EnumArrayable, EnumTranslatable;

    case Standard = 'standard';
    case CreditNote = 'credit_note';

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "invoice::enums.invoice_kinds";
    }

    /**
     * Determine if kind is credit note
     *
     * @return bool
     */
    public function isCreditNote(): bool
    {
        return $this === InvoiceKind::CreditNote;
    }


    /**
     * Determine if kind is standard
     *
     * @return bool
     */
    public function isStandard(): bool
    {
        return $this === InvoiceKind::Standard;
    }
}
