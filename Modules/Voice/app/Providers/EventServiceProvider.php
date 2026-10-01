<?php

namespace Modules\Voice\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderPaid;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\SeatingPlan\Events\TableUpdateStatus;
use Modules\Voice\Listeners\OrderCreatedListener;
use Modules\Voice\Listeners\OrderPaidVoiceListener;
use Modules\Voice\Listeners\OrderStatusVoiceListener;
use Modules\Voice\Listeners\TableStatusVoiceListener;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        OrderCreated::class => [
            OrderCreatedListener::class,
        ],
        OrderUpdateStatus::class => [
            OrderStatusVoiceListener::class,
        ],
        OrderPaid::class => [
            OrderPaidVoiceListener::class,
        ],
        TableUpdateStatus::class => [
            TableStatusVoiceListener::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
