<?php

namespace Modules\Payment\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PaymentMethod: string
{
    use EnumArrayable, EnumTranslatable;

    case Cash = 'cash';
    case UPI = 'upi';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case MobileWallet = 'mobile_wallet';
    case Complimentary = 'complimentary';
    case Company = 'com';


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
            PaymentMethod::UPI->value => 'tabler-qrcode',
            PaymentMethod::Card->value => 'tabler-credit-card',
            PaymentMethod::BankTransfer->value => 'tabler-building-bank',
            PaymentMethod::MobileWallet->value => 'tabler-device-mobile-dollar',
            PaymentMethod::Complimentary->value => 'tabler-gift',
            PaymentMethod::Company->value => 'tabler-building-store',
            default => 'tabler-cash',
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
            PaymentMethod::Card->value => '#27AE60',
            PaymentMethod::Cash->value => '#74B9FF',
            PaymentMethod::UPI->value => '#7C3AED',
            PaymentMethod::BankTransfer->value => '#0abde3',
            PaymentMethod::MobileWallet->value => '#01a3a4',
            PaymentMethod::Complimentary->value => '#A55EEA',
            PaymentMethod::Company->value => '#6366F1',
            default => '#74B9FF',
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
