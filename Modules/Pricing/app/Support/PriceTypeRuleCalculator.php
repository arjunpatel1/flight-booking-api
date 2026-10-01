<?php

namespace Modules\Pricing\Support;

use Modules\Pricing\Enums\PriceTypeRuleType;

final class PriceTypeRuleCalculator
{
    public static function resolve(float $basePrice, PriceTypeRuleType|string|null $ruleType, ?float $ruleValue, ?string $code = null): float
    {
        $basePrice = max($basePrice, 0);
        $ruleValue = max((float) ($ruleValue ?? 0), 0);

        if (strtoupper((string) $code) === 'SELF_SERVICE' && $ruleValue <= 0) {
            return round($basePrice, 4);
        }

        $type = $ruleType instanceof PriceTypeRuleType
            ? $ruleType
            : PriceTypeRuleType::tryFrom((string) $ruleType);

        $price = match ($type) {
            PriceTypeRuleType::Flat => $basePrice + $ruleValue,
            PriceTypeRuleType::Percent => $basePrice + (($basePrice * $ruleValue) / 100),
            PriceTypeRuleType::Fixed => $ruleValue > 0 ? $ruleValue : $basePrice,
            default => $basePrice,
        };

        return round(max($price, 0), 4);
    }
}
