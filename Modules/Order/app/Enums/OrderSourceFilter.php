<?php

namespace Modules\Order\Enums;

use Modules\Aggregator\Enums\AggregatorProvider;
use Modules\Support\Traits\EnumArrayable;

enum OrderSourceFilter: string
{
    use EnumArrayable;

    case Swiggy = 'swiggy';
    case Zomato = 'zomato';
    case Ondc = 'ondc';
    case Magicpin = 'magicpin';
    case Dunzo = 'dunzo';
    case Porter = 'porter';
    case Admin = 'admin';
    case WaiterApp = 'waiter_app';
    case CustomerApp = 'customer_app';
    case CustomerWeb = 'customer_web';
    case Direct = 'direct';
    case Portal = 'portal';
    case QR = 'qr';
    case Pos = 'pos';
    case Partner = 'partner';
    case WhatsApp = 'whatsapp';

    public static function toArrayTrans(): array
    {
        return [
            ...array_map(
                fn (AggregatorProvider $provider) => [
                    'id' => $provider->value,
                    'name' => $provider->trans(),
                ],
                AggregatorProvider::cases()
            ),
            ...array_map(
                fn (self $source) => [
                    'id' => $source->value,
                    'name' => $source->trans(),
                ],
                [self::Admin, self::WaiterApp, self::CustomerApp, self::CustomerWeb, self::Direct, self::Portal, self::QR, self::Pos]
            ),
            [
                'id' => self::Partner->value,
                'name' => self::Partner->trans(),
            ],
            [
                'id' => self::WhatsApp->value,
                'name' => self::WhatsApp->trans(),
            ],
        ];
    }

    public function trans(): string
    {
        return __("order::orders.source_filters.{$this->value}");
    }
}
