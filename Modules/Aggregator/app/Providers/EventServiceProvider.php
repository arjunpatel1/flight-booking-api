<?php

namespace Modules\Aggregator\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Aggregator\Listeners\SyncAggregatorOrderCreated;
use Modules\Aggregator\Listeners\SyncAggregatorOrderStatus;
use Modules\Aggregator\Listeners\SyncAggregatorOrderVoided;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Events\OrderVoided;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        OrderCreated::class => [
            SyncAggregatorOrderCreated::class,
        ],
        OrderUpdateStatus::class => [
            SyncAggregatorOrderStatus::class,
        ],
        OrderVoided::class => [
            SyncAggregatorOrderVoided::class,
        ],
    ];
}
