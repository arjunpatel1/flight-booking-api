<?php

namespace Modules\Flight\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Flight\Enums\BookingStatus;
use Modules\Flight\Enums\HoldStatus;
use Modules\Flight\Enums\PaymentStatus;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightHold;
use Modules\Flight\Services\Hold\FlightHoldService;

/**
 * One-off repair. Wallet (B2B) and webhook payments used to leave the booking's
 * hold ACTIVE, so when its timer ran out the paid seat went back on sale. This
 * takes those seats again for every paid, still-active booking whose hold ended
 * as EXPIRED or RELEASED. Safe to re-run: converted holds are skipped.
 */
class RepairPaidHoldSeats extends Command
{
    protected $signature = 'flight:repair-paid-hold-seats {--dry-run : List the bookings without changing anything}';

    protected $description = 'Retake inventory seats for paid bookings whose hold expired and returned the seat';

    public function handle(FlightHoldService $holds): int
    {
        $bookings = FlightBooking::query()
            ->withoutGlobalScopes()
            ->where('payment_status', PaymentStatus::PAID->value)
            ->whereIn('booking_status', [
                BookingStatus::PNR_PENDING->value,
                BookingStatus::BOOKED->value,
                BookingStatus::CONFIRMED->value,
                BookingStatus::COMPLETED->value,
            ])
            ->whereNotNull('hold_id')
            ->whereIn('hold_id', FlightHold::query()
                ->withoutGlobalScopes()
                ->whereIn('status', [HoldStatus::EXPIRED->value, HoldStatus::RELEASED->value])
                ->select('id'))
            ->get();

        if ($bookings->isEmpty()) {
            $this->info('No paid bookings have lost their seats.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        foreach ($bookings as $booking) {
            $this->line(sprintf(
                '%s %s: inventory #%d, %d seat(s)',
                $dryRun ? 'Would retake' : 'Retaking',
                $booking->booking_reference,
                $booking->flight_inventory_id,
                (int) $booking->pax_count_adult + (int) $booking->pax_count_child,
            ));
            if (! $dryRun) {
                DB::transaction(fn () => $holds->convertForPaidBooking($booking));
            }
        }

        $this->info(($dryRun ? 'Would repair ' : 'Repaired ').$bookings->count().' booking(s).');

        return self::SUCCESS;
    }
}
