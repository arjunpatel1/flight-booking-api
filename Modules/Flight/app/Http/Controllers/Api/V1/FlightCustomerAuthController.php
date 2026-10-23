<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightCustomer;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Services\Ticketing\FlightEticketPdfService;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class FlightCustomerAuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
            'phone' => preg_replace('/\D+/', '', (string) $request->input('phone')),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:160', Rule::unique('flight_customers', 'email')],
            'phone' => ['required', 'regex:/^[0-9]{7,20}$/', Rule::unique('flight_customers', 'phone')],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $customer = FlightCustomer::query()->create([
            'name' => trim($data['name']),
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
            'frequent_flyer_number' => $this->newFrequentFlyerNumber(),
            'membership_tier' => 'Silver',
            'miles_balance' => 3000,
            'is_active' => true,
        ]);

        return ApiResponse::created($this->createSession($customer, (bool) ($data['remember'] ?? true)), message: 'Your flight customer account is ready.');
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:160'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $login = trim($data['login']);
        $normalizedPhone = preg_replace('/\D+/', '', $login);
        $rateLimitKey = 'flight-customer-login:' . sha1($request->ip() . '|' . Str::lower($login));

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return ApiResponse::errors(['code' => 'TOO_MANY_ATTEMPTS'], 'Too many login attempts. Try again shortly.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $customer = FlightCustomer::query()
            ->where(function ($query) use ($login, $normalizedPhone) {
                $query->where('email', Str::lower($login));
                if ($normalizedPhone !== '') {
                    $query->orWhere('phone', $normalizedPhone);
                }
            })
            ->first();

        if (! $customer || ! Hash::check($data['password'], (string) $customer->password)) {
            RateLimiter::hit($rateLimitKey, 60);
            return ApiResponse::errors(['code' => 'INVALID_FLIGHT_CUSTOMER_CREDENTIALS'], 'Email, phone, or password is incorrect.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $customer->is_active) {
            return ApiResponse::errors(['code' => 'FLIGHT_CUSTOMER_ACCOUNT_DISABLED'], 'This flight customer account is disabled.', Response::HTTP_FORBIDDEN);
        }

        RateLimiter::clear($rateLimitKey);

        return ApiResponse::success($this->createSession($customer, (bool) ($data['remember'] ?? false)));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return ApiResponse::success(null, message: 'Signed out successfully.');
    }

    public function bookings(Request $request): JsonResponse
    {
        $customer = $request->user();
        abort_unless($customer instanceof FlightCustomer, 403, 'A flight customer account is required.');

        $bookings = FlightBooking::query()
            ->where(function ($query) use ($customer): void {
                $query->where('flight_customer_id', $customer->id)
                    ->orWhereRaw('LOWER(contact_email) = ?', [Str::lower($customer->email)]);
            })
            // The stored itinerary already contains the customer-facing route.
            // Avoid loading FlightInventory here: its tenant global scope calls
            // staff-only role methods on the authenticated user.
            ->with(['passengers.ticket', 'hold'])
            ->latest('id')
            ->paginate(20);

        return ApiResponse::success([
            'data' => $bookings->getCollection()->map(function (FlightBooking $booking): array {
                $status = $booking->booking_status;
                $paymentStatus = $booking->payment_status;
                $itinerary = (array) ($booking->itinerary ?? []);
                $departureDate = data_get($itinerary, 'departure.date')
                    ?: data_get($itinerary, 'departure_date')
                    ?: data_get($booking->meta, 'departure_date')
                    ?: data_get($booking->hold?->meta, 'departure_date');
                if ($departureDate) {
                    $itinerary['departure_date'] = $departureDate;
                    $departure = (array) ($itinerary['departure'] ?? []);
                    $departure['date'] = $departureDate;
                    $itinerary['departure'] = $departure;
                }

                return [
                    'id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'pnr' => $booking->pnr,
                    'booking_status' => is_object($status) ? $status->value : (string) $status,
                    'ticket_status' => is_object($booking->ticket_status)
                        ? $booking->ticket_status->value
                        : ($booking->ticket_status !== null ? (string) $booking->ticket_status : null),
                    'manual_pnr_required' => $booking->manual_pnr_required,
                    'payment_status' => is_object($paymentStatus) ? $paymentStatus->value : (string) $paymentStatus,
                    'total_amount' => (float) $booking->total_amount,
                    'currency' => $booking->currency,
                    'created_at' => $booking->created_at?->toIso8601String(),
                    'itinerary' => $itinerary,
                    'passengers' => $booking->passengers->map(fn ($passenger): array => [
                        'name' => $passenger->full_name,
                        'ticket_number' => $passenger->ticket?->ticket_number,
                    ])->values(),
                ];
            })->values(),
            'current_page' => $bookings->currentPage(),
            'last_page' => $bookings->lastPage(),
            'total' => $bookings->total(),
        ]);
    }

    public function downloadBookingTicket(Request $request, string $identifier): Response
    {
        $booking = $this->findCustomerBooking($request, $identifier);
        abort_if($booking->manual_pnr_required && ! $booking->isTicketed(), 409, 'Your booking is paid, but the e-ticket is not available yet.');

        $pdf = app(FlightEticketPdfService::class)->generate($booking);
        $safeRef = preg_replace('/[^A-Za-z0-9_-]/', '', $booking->booking_reference);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="eticket-' . $safeRef . '.pdf"',
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    public function previewBookingTicket(Request $request, string $identifier): Response
    {
        $booking = $this->findCustomerBooking($request, $identifier);
        abort_if($booking->manual_pnr_required && ! $booking->isTicketed(), 409, 'Your booking is paid, but the e-ticket is not available yet.');
        $html = app(FlightEticketPdfService::class)->renderHtml($booking);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    private function findCustomerBooking(Request $request, string $identifier): FlightBooking
    {
        $customer = $request->user();
        abort_unless($customer instanceof FlightCustomer, 403, 'A flight customer account is required.');

        $booking = FlightBooking::query()
            ->where(function ($query) use ($customer): void {
                $query->where('flight_customer_id', $customer->id)
                    ->orWhereRaw('LOWER(contact_email) = ?', [Str::lower($customer->email)]);
            })
            ->where(function ($query) use ($identifier): void {
                $query->where('booking_reference', $identifier);
                if (is_numeric($identifier)) {
                    $query->orWhere('id', (int) $identifier);
                }
            })
            ->with(['passengers.ticket', 'tickets', 'ancillaries'])
            ->firstOrFail();

        $paymentStatus = $booking->payment_status;
        $paymentStatus = is_object($paymentStatus) ? $paymentStatus->value : (string) $paymentStatus;
        abort_unless(strtoupper($paymentStatus) === 'PAID', 422, 'The e-ticket is available after payment is completed.');

        // The saved itinerary contains the route details. Load inventory only
        // for ticket fields that need it, bypassing the staff tenant scope only
        // after this booking has been restricted to the signed-in customer.
        $inventory = $booking->flight_inventory_id
            ? FlightInventory::withoutGlobalScope('tenant_permission')
                ->with(['airline', 'originAirport', 'destinationAirport'])
                ->find($booking->flight_inventory_id)
            : null;
        $booking->setRelation('flightInventory', $inventory);

        return $booking;
    }

    private function createSession(FlightCustomer $customer, bool $remember): array
    {
        $expiresAt = now()->addDays($remember ? 30 : 1);
        $token = $customer->createToken('flight-booking-web', ['flight-customer'], $expiresAt)->plainTextToken;

        return [
            'token' => $token,
            'customer' => $this->customerPayload($customer),
        ];
    }

    private function customerPayload(FlightCustomer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'frequent_flyer_number' => $customer->frequent_flyer_number,
            'membership_tier' => $customer->membership_tier,
            'miles_balance' => (int) $customer->miles_balance,
            'avatar' => null,
        ];
    }

    private function newFrequentFlyerNumber(): string
    {
        do {
            $number = 'CRT-' . strtoupper(Str::random(8));
        } while (FlightCustomer::query()->where('frequent_flyer_number', $number)->exists());

        return $number;
    }
}
