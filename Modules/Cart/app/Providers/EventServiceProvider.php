<?php

namespace Modules\Cart\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Cart\Events\CustomerGroupCartUpdated;
use Modules\Cart\Listeners\ClearCart;
use Modules\Cart\Listeners\DispatchCustomerGroupNotification;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderUpdated;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        OrderCreated::class => [
            ClearCart::class,
        ],
        OrderUpdated::class => [
            ClearCart::class,
        ],
        CustomerGroupCartUpdated::class => [
            DispatchCustomerGroupNotification::class,
        ],
    ];
}
