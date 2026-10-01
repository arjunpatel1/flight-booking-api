<?php

namespace Modules\Pos\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Pos\Events\ClosePosSession;
use Modules\Pos\Events\OpenPosSession;
use Modules\Pos\Listeners\UpdateRegisterLastSession;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        OpenPosSession::class => [
            UpdateRegisterLastSession::class,
        ],
        ClosePosSession::class => [
            UpdateRegisterLastSession::class,
        ]
    ];
}
