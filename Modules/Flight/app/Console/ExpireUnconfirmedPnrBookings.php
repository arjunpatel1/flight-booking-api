<?php

namespace Modules\Flight\Console;

use Illuminate\Console\Command;
use Modules\Flight\Services\Booking\FlightPnrAllocationService;

/**
 * Sells out paid bookings whose airline PNR did not arrive within the tenant's
 * time limit (Booking Settings, default 30 minutes): full refund + email.
 */
class ExpireUnconfirmedPnrBookings extends Command
{
    protected $signature = 'flight:expire-unconfirmed-pnr';

    protected $description = 'Cancel, with a full refund, paid flight bookings whose PNR was not confirmed in time';

    public function handle(FlightPnrAllocationService $pnrAllocation): int
    {
        $count = $pnrAllocation->expireUnconfirmed();
        $this->info("Sold out {$count} booking(s) without a PNR.");

        return self::SUCCESS;
    }
}
