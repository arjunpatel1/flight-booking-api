<?php

namespace Modules\Payment\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PaymentMode: string
{
    use EnumArrayable, EnumTranslatable;

    case Full = 'full';
    case Partial = 'partial';


    /** @inheritDoc */
    public static function toArrayTrans(): array
    {
        $cases = [];

        foreach (self::values() as $value) {
            $cases[] = [
                "id" => $value,
                "name" => __(PaymentMode::getTransKey() . ".$value"),
                "icon" => PaymentMode::getIcon($value),
                "color" => PaymentMode::getColor($value),
            ];
        }

        return $cases;
    }

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "payment::enums.payment_modes";
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
            PaymentMode::Full->value => 'tabler-circle-check',
            PaymentMode::Partial->value => 'tabler-circle-half',
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
            PaymentMode::Full->value => '#0097e6',
            PaymentMode::Partial->value => '#e1b12c',
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
            "icon" => PaymentMode::getIcon($this->value),
            "color" => PaymentMode::getColor($this->value),
        ];
    }
}
