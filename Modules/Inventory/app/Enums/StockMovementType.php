<?php

namespace Modules\Inventory\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum StockMovementType: string
{
    use EnumArrayable, EnumTranslatable;

    case In = 'in'; // Purchase or manual addition
    case Out = 'out'; // Usage for sales or kitchen production
    case Spoil = 'spoil'; // Lost or expired items
    case AdjustAdd = 'adjust_add'; // Manual correction (positive)
    case AdjustSubtract = 'adjust_subtract'; // Manual correction (negative)
    case TransferIn = 'transfer_in'; // Received from another branch
    case TransferOut = 'transfer_out'; // Sent to another branch
    case ReturnToSupplier = 'return_supplier'; // Returned to vendor
    case Sample = 'sample'; // Taken as sample/tasting
    case Waste = 'waste'; // Wasted during prep or handling

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "inventory::enums.stock_movement_types";
    }

    /**
     * Determine if the stock movement type increases the current stock.
     *
     * @return bool True if the movement is incoming (adds to stock), false otherwise.
     */
    public function isIncoming(): bool
    {
        return in_array(
            $this,
            [
                self::In, self::AdjustAdd, self::TransferIn,
            ]
        );
    }

    /**
     * Determine if the stock movement type decreases the current stock.
     *
     * @return bool True if the movement is outgoing (reduces stock), false otherwise.
     */
    public function isOutgoing(): bool
    {
        return in_array(
            $this,
            [
                self::Out,
                self::Spoil,
                self::AdjustSubtract,
                self::TransferOut,
                self::ReturnToSupplier,
                self::Sample,
                self::Waste,
            ]
        );
    }
}
