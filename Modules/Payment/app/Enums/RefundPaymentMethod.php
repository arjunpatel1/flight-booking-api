<?php

namespace Modules\Payment\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum RefundPaymentMethod: string
{
    use EnumArrayable, EnumTranslatable;

    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';


    /** @inheritDoc */
    public static function toArrayTrans(): array
    {
        $cases = [];

        foreach (self::values() as $value) {
            $cases[] = [
                "id" => $value,
                "name" => __(PaymentMethod::getTransKey() . ".$value"),
                "icon" => PaymentMethod::getIcon($value),
                "color" => PaymentMethod::getColor($value),
            ];
        }

        return $cases;
    }

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "payment::enums.payment_methods";
    }

    /**
     * Get type icon by value
     *
     * @param string $value
     * @return string
     */
    public static function getIcon(string $value): string
    {
        return match ($value) {
            PaymentMethod::Cash->value => 'tabler-cash',
            PaymentMethod::BankTransfer->value => 'tabler-building-bank',
        };
    }

    /**
     * Get type color by value
     *
     * @param string $value
     * @return string
     */
    public static function getColor(string $value): string
    {
        return match ($value) {
            PaymentMethod::Cash->value => '#74B9FF',
            PaymentMethod::BankTransfer->value => '#0abde3',
        };
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
            "icon" => PaymentMethod::getIcon($this->value),
            "color" => PaymentMethod::getColor($this->value),
        ];
    }
}
