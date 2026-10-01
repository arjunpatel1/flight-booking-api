<?php

namespace Modules\Order\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum OrderType: string
{
    use EnumTranslatable, EnumArrayable;

    case DineIn = "dine_in";
    case SelfService = 'self_service';
    case Takeaway = "takeaway";
    case Delivery = 'delivery';
    case Pickup = "pick_up";

    case DriveThru = 'drive_thru';
    case PreOrder = 'pre_order';
    case Catering = 'catering';

    /** @inheritDoc */
    public static function toArrayTrans(array $except = []): array
    {
        $cases = [];

        foreach (self::values() as $value) {
            if (in_array($value, $except)) {
                continue;
            }
            $cases[] = [
                "id" => $value,
                "name" => __(OrderType::getTransKey() . ".$value"),
                "icon" => OrderType::getIcon($value),
                "color" => OrderType::getColor($value),
            ];
        }

        return $cases;
    }

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "order::enums.order_types";
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
            OrderType::DineIn->value => 'tabler-brand-airtable',
            OrderType::Pickup->value => 'tabler-truck',
            OrderType::Takeaway->value => 'tabler-paper-bag',
            OrderType::DriveThru->value => 'tabler-car',
            OrderType::PreOrder->value => 'tabler-calendar-clock',
            OrderType::Catering->value => 'tabler-users-group',
            OrderType::SelfService->value => 'tabler-bell-ringing',
            OrderType::Delivery->value => 'tabler-truck-delivery',
            default => 'tabler-question-mark',
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
            OrderType::DineIn->value => '#3867D6',
            OrderType::Pickup->value => '#20BF6B',
            OrderType::Takeaway->value => '#F7B731',
            OrderType::DriveThru->value => '#FF6B6B',
            OrderType::PreOrder->value => '#A55EEA',
            OrderType::Catering->value => '#00cec9',
            OrderType::SelfService->value => '#0EA5E9',
            OrderType::Delivery->value => '#8B5CF6',
            default => '#8395A7',
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
            "icon" => OrderType::getIcon($this->value),
            "color" => OrderType::getColor($this->value),
        ];
    }
}
