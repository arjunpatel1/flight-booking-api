<?php

namespace Modules\Flight\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Flight\Models\FlightB2BWallet;
use Modules\Flight\Models\FlightWalletTransaction;

class FlightWalletDebited
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly FlightWalletTransaction $transaction,
        public readonly FlightB2BWallet $wallet
    ) {
    }
}
