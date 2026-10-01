<?php

namespace Modules\SeatingPlan\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\SeatingPlan\Events\TableAssignWaiter;
use Modules\SeatingPlan\Events\TableUpdateStatus;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        TableUpdateStatus::class => [],
        TableAssignWaiter::class => []
    ];
}
