<?php

namespace Modules\Pos\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PosCashReason: string
{
    use EnumTranslatable, EnumArrayable;

    case Sale = 'sale';             // cash sale (usually auto from POS payment)
    case Refund = 'refund';           // cash refund to customer
    case PayIn = 'pay_in';           // add cash to drawer (float top-up)
    case PayOut = 'pay_out';          // remove cash (supplier payout, petty cash)
    case CashDrop = 'cash_drop';        // move excess cash to safe (mid-shift drop)
    case ClosingAdjust = 'closing_adjust';   // correction at close (declared vs system)
    case TipIn = 'tip_in';           // cash tips added to drawer
    case TipOut = 'tip_out';          // tips taken out (distribution)
    case Correction = 'correction';       // manual correction (audit)

    /**
     * Get pos cash Reason for manage cash movement
     *
     * @param PosCashDirection $direction
     * @return array
     */
    public static function getForManageCashMovement(PosCashDirection $direction): array
    {
        return match ($direction) {
            PosCashDirection::In => [
                PosCashReason::PayIn,
                PosCashReason::TipIn,
                PosCashReason::Correction
            ],
            PosCashDirection::Out => [
                PosCashReason::PayOut,
                PosCashReason::TipOut,
                PosCashReason::Refund,
                PosCashReason::CashDrop,
                PosCashReason::Correction
            ],
            default => []
        };
    }

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "pos::enums.pos_cash_reasons";
    }

    /**
     * Get enum as array value with trans
     *
     * @return array
     */
    public function toTrans(): array
    {
        return [
            "id" => $this->value,
            "name" => $this->trans(),
            "icon" => $this->icon(),
            "color" => $this->color(),
        ];
    }

    /**
     * Get icon for cash movement reason
     *
     * @return string
     */
    public function icon(): string
    {
        return match ($this) {
            self::Sale => "tabler-cash-register",
            self::Refund => "tabler-rotate-clockwise",
            self::PayIn => "tabler-arrow-big-up-lines",
            self::PayOut => "tabler-arrow-big-down-lines",
            self::CashDrop => "tabler-building-bank",
            self::ClosingAdjust => "tabler-scale",
            self::TipIn => "tabler-coins",
            self::TipOut => "tabler-heart-handshake",
            self::Correction => "tabler-edit-circle",
        };
    }

    /**
     * Get color for cash movement reason
     *
     * @return string
     */
    public function color(): string
    {
        return match ($this) {
            self::Sale => "#2ECC71",
            self::Refund => "#E74C3C",
            self::PayIn => "#3498DB",
            self::PayOut => "#9B59B6",
            self::CashDrop => "#F39C12",
            self::ClosingAdjust => "#16A085",
            self::TipIn => "#1ABC9C",
            self::TipOut => "#F1C40F",
            self::Correction => "#7F8C8D",
        };
    }
}
