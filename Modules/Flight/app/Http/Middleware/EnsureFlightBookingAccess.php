<?php

namespace Modules\Flight\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightCustomer;
use Modules\Flight\Models\FlightPayment;
use Modules\Flight\Services\Customer\FlightCustomerOtpService;
use Modules\User\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the public, booking-keyed endpoints (booking details, payment,
 * e-ticket). A booking reference is printed on tickets and shared, and ids
 * are sequential, so neither proves anything. A request passes when it comes
 * from:
 *  - staff of the booking's agency (or a platform admin),
 *  - the signed-in customer who owns the booking, or
 *  - the guest holding the token issued when the booking was created
 *    (X-Booking-Token header).
 * Everyone else gets 404, so the endpoint does not confirm a booking exists.
 */
class EnsureFlightBookingAccess
{
    public const HEADER = 'X-Booking-Token';

    public function __construct(private readonly FlightCustomerOtpService $customers) {}

    public function handle(Request $request, Closure $next): Response
    {
        $booking = $this->resolveBooking($request);

        abort_unless($booking && $this->allows($request, $booking), Response::HTTP_NOT_FOUND, 'Booking not found.');

        return $next($request);
    }

    private function resolveBooking(Request $request): ?FlightBooking
    {
        if ($paymentReference = $request->route('reference')) {
            $bookingId = FlightPayment::query()->where('payment_reference', $paymentReference)->value('flight_booking_id');

            return $bookingId ? FlightBooking::query()->find($bookingId) : null;
        }

        $identifier = (string) $request->route('identifier');

        return FlightBooking::query()
            ->where(function ($query) use ($identifier): void {
                $query->where('booking_reference', $identifier);
                if (is_numeric($identifier)) {
                    $query->orWhere('id', (int) $identifier);
                }
            })
            ->first();
    }

    private function allows(Request $request, FlightBooking $booking): bool
    {
        if ($booking->acceptsAccessToken($request->header(self::HEADER))) {
            return true;
        }

        $staff = $request->user('api');
        if ($staff instanceof User) {
            // Same rule as the tenant scopes: platform admins and users with no
            // agency of their own see everything; agency staff see their agency.
            return $staff->isSuperAdmin()
                || ! $staff->assignedToTenant()
                || $staff->tenantId() === (int) $booking->tenant_id;
        }

        $customer = $request->user('sanctum');
        if ($customer instanceof FlightCustomer) {
            return $this->customers->ownedBy(FlightBooking::query(), $customer)->whereKey($booking->id)->exists();
        }

        return false;
    }
}
