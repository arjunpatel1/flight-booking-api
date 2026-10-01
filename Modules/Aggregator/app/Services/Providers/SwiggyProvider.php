<?php

namespace Modules\Aggregator\Services\Providers;

class SwiggyProvider extends ConfiguredAggregatorProvider
{
    public function __construct()
    {
        parent::__construct('swiggy');
    }

    public function capabilities(): array
    {
        return [
            'supports_menu_sync' => false,
            'supports_status_push' => false,
            'supports_inventory_sync' => false,
            'supports_refunds' => false,
            'supports_delivery_tracking' => false,
        ];
    }
}
