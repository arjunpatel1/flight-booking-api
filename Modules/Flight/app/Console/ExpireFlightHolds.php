<?php

namespace Modules\Flight\Console;

use Illuminate\Console\Command;
use Modules\Flight\Services\Hold\FlightHoldService;
use Modules\Flight\Services\Servicing\FlightServicingService;

/**
 * Puts the seats of timed-out fare locks back on sale, so an abandoned checkout
 * (browser closed, no further searches) doesn't keep them locked. Unpaid
 * reschedule payment links are ended the same way.
 */
class ExpireFlightHolds extends Command
{
    protected $signature = 'flight:expire-holds';

    protected $description = 'Release flight seat holds whose fare lock timer has run out';

    public function handle(FlightHoldService $holds, FlightServicingService $servicing): int
    {
        $count = $holds->expireActiveHolds();
        $this->info("Released {$count} expired hold(s).");

        $links = $servicing->expirePendingReschedules();
        $this->info("Ended {$links} expired reschedule payment link(s).");

        return self::SUCCESS;
    }
}
