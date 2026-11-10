<?php

namespace Modules\Flight\Services\Inventory;

use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Collection;
use Modules\Flight\Enums\BookingStatus;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Models\FlightRoute;
use Modules\Flight\Services\Servicing\FlightRefundPolicyService;

/**
 * Deleting a flight, cabin, fare or route that still has bookings would leave
 * customers holding tickets the system no longer knows about. Those bookings
 * must be cancelled or moved first.
 */
class FlightDeletionGuard
{
    /** Bookings that are still open: not cancelled, failed or completed. */
    private const CLOSED = [BookingStatus::CANCELLED, BookingStatus::FAILED, BookingStatus::COMPLETED];

    public function __construct(private FlightRefundPolicyService $policy)
    {
    }

    /** @param iterable<int>|int $inventoryIds */
    public function assertInventoriesFree(iterable|int $inventoryIds, string $what = 'this flight'): void
    {
        $ids = collect(is_int($inventoryIds) ? [$inventoryIds] : $inventoryIds)->filter()->values();
        if ($ids->isEmpty()) {
            return;
        }

        $this->assertNone($this->openBookings(fn ($query) => $query->whereIn('flight_inventory_id', $ids)), $what);
    }

    public function assertFareFree(int $fareId): void
    {
        $this->assertNone($this->openBookings(fn ($query) => $query->where('flight_fare_id', $fareId)), 'this fare');
    }

    /** A dated route is the flight on one day: its bookings are that flight's bookings on that date. */
    public function assertRouteFree(FlightRoute $route): void
    {
        $inventoryIds = FlightInventory::query()->withoutGlobalScopes()
            ->where('tenant_id', $route->tenant_id)
            ->where('flight_number', $route->flight_number)
            ->where('origin_airport_id', $route->origin_airport_id)
            ->where('destination_airport_id', $route->destination_airport_id)
            ->get(['id', 'departure_time'])
            ->filter(fn ($inventory) => ! $route->flight_time
                || substr((string) $inventory->departure_time, 0, 5) === substr((string) $route->flight_time, 0, 5))
            ->pluck('id');
        if ($inventoryIds->isEmpty()) {
            return;
        }

        $date = $route->flight_date?->toDateString();
        $bookings = $this->openBookings(fn ($query) => $query->whereIn('flight_inventory_id', $inventoryIds))
            ->filter(fn (FlightBooking $booking) => $date === null
                || $this->policy->departureAt($booking)?->toDateString() === $date);

        $this->assertNone($bookings, 'this route');
    }

    /** Open bookings whose flight has not departed yet. */
    private function openBookings(callable $scope): Collection
    {
        $query = FlightBooking::query()->withoutGlobalScopes()->whereNotIn('booking_status', array_map(fn ($status) => $status->value, self::CLOSED));
        $scope($query);

        return $query->get()->filter(function (FlightBooking $booking) {
            $departure = $this->policy->departureAt($booking);

            // Unknown date: keep it, so a booking is never orphaned by mistake.
            return $departure === null || $departure->gte(Carbon::today());
        })->values();
    }

    private function assertNone(Collection $bookings, string $what): void
    {
        if ($bookings->isEmpty()) {
            return;
        }

        $count = $bookings->count();
        $references = $bookings->take(3)->pluck('booking_reference')->implode(', ');
        $more = $count > 3 ? ' and ' . ($count - 3) . ' more' : '';

        throw new DomainException(sprintf(
            '%d active booking%s %s on %s (%s%s). Cancel or reschedule %s first, then delete it.',
            $count,
            $count === 1 ? '' : 's',
            $count === 1 ? 'is' : 'are',
            $what,
            $references,
            $more,
            $count === 1 ? 'it' : 'them',
        ));
    }
}
