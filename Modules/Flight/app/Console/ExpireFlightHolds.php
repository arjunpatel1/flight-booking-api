<?php

namespace Modules\Flight\Console;

use Illuminate\Console\Command;
use Modules\Flight\Services\Hold\FlightHoldService;

/**
 * Puts the seats of timed-out fare locks back on sale, so an abandoned checkout
 * (browser closed, no further searches) doesn't keep them locked.
 */
class ExpireFlightHolds extends Command
{
    protected $signature = 'flight:expire-holds';

    protected $description = 'Release flight seat holds whose fare lock timer has run out';

    public function handle(FlightHoldService $holds): int
    {
        $count = $holds->expireActiveHolds();
        $this->info("Released {$count} expired hold(s).");

        return self::SUCCESS;
    }
}
