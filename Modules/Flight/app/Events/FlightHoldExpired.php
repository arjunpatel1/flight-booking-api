<?php

namespace Modules\Flight\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Flight\Models\FlightHold;

class FlightHoldExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly FlightHold $hold
    ) {
    }
}
