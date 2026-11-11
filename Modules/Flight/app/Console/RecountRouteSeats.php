<?php

namespace Modules\Flight\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Flight\Enums\BookingStatus;
use Modules\Flight\Enums\HoldStatus;
use Modules\Flight\Enums\PaymentStatus;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightBookingAmendment;
use Modules\Flight\Models\FlightHold;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Models\FlightRoute;
use Modules\Flight\Services\Inventory\FlightSeatPool;

/**
 * Rebuild every dated route's consumed/available seats from what really holds
 * a seat right now: active holds, paid bookings that are not cancelled, and
 * reschedule seats held for a payment link. Used once when seats moved from
 * per-cabin inventory to the per-date route, and safe to re-run any time the
 * numbers look wrong.
 */
class RecountRouteSeats extends Command
{
    protected $signature = 'flight:recount-route-seats {--dry-run : Show the new numbers without saving them}';

    protected $description = 'Recount sold seats on each dated flight route (all cabins together)';

    public function handle(FlightSeatPool $pool): int
    {
        /** @var array<int, int> $consumed route id => seats */
        $consumed = [];
        $add = function (?FlightInventory $inventory, ?string $date, int $seats) use ($pool, &$consumed): void {
            $route = $inventory ? $pool->routeFor($inventory, $date) : null;
            if ($route && $seats > 0) {
                $consumed[$route->id] = ($consumed[$route->id] ?? 0) + $seats;
            }
        };

        // 1. Seats held right now (checkout in progress, or paid but not yet converted).
        $activeHolds = FlightHold::query()->withoutGlobalScopes()
            ->with('flightInventory')
            ->where('status', HoldStatus::ACTIVE->value)
            ->where('expires_at', '>', now())
            ->get();
        foreach ($activeHolds as $hold) {
            $add($hold->flightInventory, $pool->holdDate($hold), (int) $hold->quantity);
        }
        $activeHoldIds = $activeHolds->pluck('id')->all();

        // 2. Paid bookings still flying whose hold has ended (sold seats).
        $bookings = FlightBooking::query()->withoutGlobalScopes()
            ->with('flightInventory')
            ->where('payment_status', PaymentStatus::PAID->value)
            ->whereIn('booking_status', [
                BookingStatus::PNR_PENDING->value,
                BookingStatus::BOOKED->value,
                BookingStatus::CONFIRMED->value,
                BookingStatus::COMPLETED->value,
            ])
            ->get();
        foreach ($bookings as $booking) {
            if ($booking->hold_id && in_array($booking->hold_id, $activeHoldIds, true)) {
                continue; // already counted with its hold
            }
            $seats = max(1, $booking->passengers()->count() ?: ((int) $booking->pax_count_adult + (int) $booking->pax_count_child));
            $add($booking->flightInventory, $pool->bookingDate($booking), $seats);
        }

        // 3. Seats held on a new flight while a reschedule waits for payment.
        if (class_exists(FlightBookingAmendment::class)) {
            $amendments = FlightBookingAmendment::query()->withoutGlobalScopes()
                ->where('status', 'QUOTED')
                ->whereNotNull('new_flight_inventory_id')
                ->get();
            foreach ($amendments as $amendment) {
                $add(
                    FlightInventory::query()->withoutGlobalScopes()->find($amendment->new_flight_inventory_id),
                    substr((string) data_get($amendment->audit_trail, 'new_departure_time', ''), 0, 10) ?: null,
                    (int) data_get($amendment->audit_trail, 'pax_count', 1),
                );
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $routes = FlightRoute::query()->withoutGlobalScopes()
            ->whereNotNull('flight_date')
            ->where('total_seats', '>', 0)
            ->orderBy('flight_date')
            ->get();
        foreach ($routes as $route) {
            $sold = $consumed[$route->id] ?? 0;
            $total = (int) $route->total_seats;
            $available = max(0, $total - $sold);
            $changed = (int) $route->consumed_seats !== $sold || (int) $route->available_seats !== $available;
            if (! $changed) {
                continue;
            }
            $rows[] = [
                $route->flight_number,
                $route->flight_date?->toDateString(),
                substr((string) $route->flight_time, 0, 5),
                "{$route->consumed_seats} → {$sold}",
                "{$route->available_seats} → {$available}",
                $total,
            ];
            if (! $dryRun) {
                DB::transaction(fn () => FlightRoute::query()->withoutGlobalScopes()->lockForUpdate()->find($route->id)
                    ?->forceFill(['consumed_seats' => $sold, 'available_seats' => $available])->save());
            }
            if ($sold > $total) {
                $this->warn("{$route->flight_number} on {$route->flight_date?->toDateString()}: {$sold} seats sold but only {$total} configured (oversold).");
            }
        }

        if ($rows === []) {
            $this->info('All route seat counts are already correct.');

            return self::SUCCESS;
        }

        $this->table(['Flight', 'Date', 'Time', 'Consumed', 'Available', 'Total'], $rows);
        $this->info(($dryRun ? 'Would update ' : 'Updated ').count($rows).' route(s).');

        return self::SUCCESS;
    }
}
