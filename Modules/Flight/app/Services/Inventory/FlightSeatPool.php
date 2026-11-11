<?php

namespace Modules\Flight\Services\Inventory;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightHold;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Models\FlightRoute;
use RuntimeException;

/**
 * Where a flight's seats are counted.
 *
 * A dated route (flight_routes row: one flight on one date) is the seat pool:
 * its total_seats is shared by every cabin and fare family of that flight on
 * that date, and every hold/booking takes from it. Inventory rows carry no
 * date and exist per cabin, so counting there made every cabin a separate
 * bucket and every date of the same flight share one.
 *
 * Inventory without a dated route (recurring operating-day flights) keeps
 * using its own available_seats, so nothing that relied on it changes.
 *
 * Callers must run take()/give() inside a DB transaction.
 */
class FlightSeatPool
{
    /** The dated route for this flight on this date, if the agency has one. */
    public function routeFor(FlightInventory $inventory, ?string $date, bool $lock = false): ?FlightRoute
    {
        $date = $this->normaliseDate($date);
        if (! $date) {
            return null;
        }

        $clock = $this->clockOf($inventory->departure_time);

        return FlightRoute::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $inventory->tenant_id)
            ->where('flight_number', $inventory->flight_number)
            ->where('origin_airport_id', $inventory->origin_airport_id)
            ->where('destination_airport_id', $inventory->destination_airport_id)
            ->whereDate('flight_date', $date)
            // A route saved without a capacity is a schedule only, not a seat pool.
            ->where('total_seats', '>', 0)
            ->when($clock, fn (Builder $query) => $query->where(fn (Builder $time) => $time
                ->whereNull('flight_time')
                ->orWhereTime('flight_time', $clock)))
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->orderBy('id')
            ->first();
    }

    /** Seats still for sale on this flight and date. */
    public function available(FlightInventory $inventory, ?string $date): int
    {
        $route = $this->routeFor($inventory, $date);

        return $route ? $this->routeAvailable($route) : max(0, (int) $inventory->available_seats);
    }

    /** Seats left on a dated route (all cabins together). */
    public function routeAvailable(FlightRoute $route): int
    {
        return max(0, (int) $route->available_seats);
    }

    /** Take seats for a hold/booking; fails when there are not enough. */
    public function take(FlightInventory $inventory, ?string $date, int $seats): void
    {
        if ($seats <= 0) {
            return;
        }

        $route = $this->routeFor($inventory, $date, lock: true);
        if ($route) {
            if ($this->routeAvailable($route) < $seats) {
                throw new RuntimeException("Only {$this->routeAvailable($route)} seat(s) left on this flight.");
            }
            $route->forceFill([
                'available_seats' => (int) $route->available_seats - $seats,
                'consumed_seats'  => (int) $route->consumed_seats + $seats,
            ])->save();

            return;
        }

        $locked = FlightInventory::query()->withoutGlobalScopes()->lockForUpdate()->find($inventory->id);
        if (! $locked || (int) $locked->available_seats < $seats) {
            throw new RuntimeException('Insufficient available seats on flight.');
        }
        $locked->decrement('available_seats', $seats);
    }

    /** Put seats back on sale (hold released/expired, booking cancelled or moved). */
    public function give(FlightInventory $inventory, ?string $date, int $seats): void
    {
        if ($seats <= 0) {
            return;
        }

        $route = $this->routeFor($inventory, $date, lock: true);
        if ($route) {
            $consumed = max(0, (int) $route->consumed_seats - $seats);
            $route->forceFill([
                'consumed_seats'  => $consumed,
                'available_seats' => max(0, (int) $route->total_seats - $consumed),
            ])->save();

            return;
        }

        FlightInventory::query()->withoutGlobalScopes()->lockForUpdate()->find($inventory->id)
            ?->increment('available_seats', $seats);
    }

    /** The travel date a hold was made for. */
    public function holdDate(FlightHold $hold, ?FlightBooking $booking = null): ?string
    {
        return $this->normaliseDate(data_get($hold->meta, 'departure_date'))
            ?? ($booking ? $this->bookingDate($booking) : null)
            ?? $this->normaliseDate($hold->flightInventory?->departure_time);
    }

    /** The travel date of a booking. */
    public function bookingDate(FlightBooking $booking): ?string
    {
        return FlightBooking::departureDateOf((array) $booking->itinerary)
            ?? $this->normaliseDate(data_get($booking->meta, 'departure_date'));
    }

    private function normaliseDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $match) ? $match[1] : null;
    }

    private function clockOf(mixed $time): ?string
    {
        if (blank($time)) {
            return null;
        }

        try {
            return Carbon::parse((string) $time)->format('H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
