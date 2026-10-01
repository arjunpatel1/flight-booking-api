<?php

namespace Modules\Order\Delivery;

use Modules\Currency\Currency;

/** Decimal arithmetic for delivery boundaries; floats are used only by the existing API money contract. */
final class DeliveryMoney
{
    public static function decimal(int|float|string $amount): string
    {
        return is_float($amount) || stripos((string) $amount, 'e') !== false
            ? rtrim(rtrim(sprintf('%.10F', (float) $amount), '0'), '.') : (string) $amount;
    }

    public static function minor(int|float|string $amount, string $currency): int
    {
        $scale = Currency::subunit($currency);
        $factor = bcpow('10', (string) $scale, 0);
        $decimal = self::decimal($amount);
        $scaled = bcmul($decimal, $factor, 10);
        return (int) bcadd($scaled, bccomp($scaled, '0', 10) < 0 ? '-0.5' : '0.5', 0);
    }

    public static function amount(int $minor, string $currency): float
    {
        return (float) bcdiv((string) $minor, bcpow('10', (string) Currency::subunit($currency), 0), Currency::subunit($currency));
    }

    public static function add(int|float|string $left, int|float|string $right, string $currency): float
    {
        return self::amount(self::minor($left, $currency) + self::minor($right, $currency), $currency);
    }
}
