<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Enums\BookingStatus;
use Modules\Flight\Enums\PaymentStatus;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightCustomer;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Services\Booking\FlightBookingService;
use Modules\Flight\Services\Customer\FlightCustomerOtpService;
use Modules\Flight\Services\Servicing\FlightRefundPolicyService;
use Modules\Flight\Services\Servicing\FlightServicingService;
use Modules\Flight\Services\Ticketing\FlightEticketPdfService;
use Modules\Saas\Models\Tenant;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class FlightCustomerAuthController extends Controller
{
    public function __construct(private readonly FlightCustomerOtpService $otpService) {}

    /** Send a login code to the mobile number entered on a booking (or an account). */
    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'device_id' => ['required', 'uuid'],
        ]);

        return ApiResponse::success(
            $this->otpService->request($request, $data['phone'], $data['device_id']),
            message: 'If this mobile number has bookings with us, a verification code has been sent.',
        );
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'otp' => ['required', 'digits:6'],
            'device_id' => ['required', 'uuid'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $customer = $this->otpService->verify($request, $data['challenge_id'], $data['otp'], $data['device_id']);

        return ApiResponse::success($this->createSession($customer, (bool) ($data['remember'] ?? true)));
    }

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
            'frequent_flyer_number' => FlightCustomer::newFrequentFlyerNumber(),
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

        $bookings = $this->otpService->ownedBy(FlightBooking::query(), $customer)
            // The stored itinerary already contains the customer-facing route.
            // Avoid loading FlightInventory here: its tenant global scope calls
            // staff-only role methods on the authenticated user.
            ->with(['passengers.ticket', 'hold', 'refunds'])
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
                    // Customer-facing refund summary (no internal gateway/failure details).
                    'refund' => ($refund = $booking->refunds->sortByDesc('id')->first()) ? [
                        'refund_reference' => $refund->refund_reference,
                        'status' => $refund->status?->value,
                        'refund_amount' => (float) $refund->refund_amount,
                        'cancellation_fee' => (float) $refund->cancellation_penalty,
                        'processed_at' => $refund->processed_at?->toIso8601String(),
                    ] : null,
                ];
            })->values(),
            'current_page' => $bookings->currentPage(),
            'last_page' => $bookings->lastPage(),
            'total' => $bookings->total(),
        ]);
    }

    /** What cancelling now would cost the customer, and whether they may do it online. */
    public function cancellationQuote(Request $request, string $identifier): JsonResponse
    {
        $booking = $this->findManageableBooking($request, $identifier);

        return ApiResponse::success($this->customerCancellationQuote($booking));
    }

    /**
     * Customer self-cancel. The customer confirms the refund amount they were
     * shown; if the quote has moved since (a fee slab passed), nothing is
     * cancelled and the new quote is returned instead.
     */
    public function cancelBooking(Request $request, string $identifier): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'expected_refund_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $customer = $request->user();
        $booking = $this->findManageableBooking($request, $identifier);
        $quote = $this->customerCancellationQuote($booking);

        if (! $quote['can_cancel']) {
            return ApiResponse::errors(['code' => 'CANCELLATION_NOT_ALLOWED', 'quote' => $quote], $quote['blocked_reason'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (abs($quote['refundable_amount'] - (float) $data['expected_refund_amount']) > 0.01) {
            return ApiResponse::errors(['code' => 'CANCELLATION_QUOTE_CHANGED', 'quote' => $quote], 'The refund amount has changed since you opened this page. Please review the new amount.', Response::HTTP_CONFLICT);
        }

        $reason = trim((string) ($data['reason'] ?? '')) ?: 'Cancelled by customer online';
        $actor = 'customer:' . $customer->id;

        try {
            if ($quote['is_paid']) {
                $result = app(FlightServicingService::class)->cancelBooking($booking, $reason, $actor);
            } else {
                app(FlightBookingService::class)->cancelUnpaidBooking($booking, $reason);
                $result = ['refund_amount' => 0.0, 'cancellation_fee' => 0.0, 'refund_status' => 'NONE'];
            }
        } catch (DomainException $e) {
            return ApiResponse::errors(['code' => 'CANCELLATION_NOT_ALLOWED'], $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return ApiResponse::success([
            'booking_reference' => $booking->booking_reference,
            'booking_status' => 'CANCELLED',
            'refund_amount' => (float) ($result['refund_amount'] ?? 0),
            'cancellation_fee' => (float) ($result['cancellation_fee'] ?? 0),
            'refund_reference' => $result['refund_reference'] ?? null,
            // Only the status: gateway failure reasons are for staff.
            'refund_status' => $result['refund_status'] ?? 'NONE',
            'refund_processing_days' => $quote['refund_processing_days'],
        ], message: 'Your booking has been cancelled.');
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

        $booking = $this->otpService->ownedBy(FlightBooking::query(), $customer)
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

    private function findManageableBooking(Request $request, string $identifier): FlightBooking
    {
        $customer = $request->user();
        abort_unless($customer instanceof FlightCustomer, 403, 'A flight customer account is required.');

        return $this->otpService->manageableBy(FlightBooking::query(), $customer)
            ->where(function ($query) use ($identifier): void {
                $query->where('booking_reference', $identifier);
                if (is_numeric($identifier)) {
                    $query->orWhere('id', (int) $identifier);
                }
            })
            ->firstOrFail();
    }

    private function customerCancellationQuote(FlightBooking $booking): array
    {
        $status = $booking->booking_status;
        $isPaid = $booking->payment_status === PaymentStatus::PAID;
        $policy = app(FlightRefundPolicyService::class)->policyFor(
            $booking->tenant_id ? Tenant::query()->withoutGlobalScopes()->find($booking->tenant_id) : null
        );
        $departure = app(FlightRefundPolicyService::class)->departureAt($booking);
        $hoursToDeparture = $departure ? now()->diffInMinutes($departure, false) / 60 : null;
        $cutoff = (int) $policy['no_show_cutoff_hours'];

        $blockedReason = match (true) {
            $status === BookingStatus::CANCELLED => 'This booking is already cancelled.',
            in_array($status, [BookingStatus::FAILED, BookingStatus::COMPLETED], true) => 'This booking can no longer be cancelled.',
            ! $status->canTransitionTo(BookingStatus::CANCELLED) => 'This booking can no longer be cancelled.',
            $hoursToDeparture !== null && $hoursToDeparture < 0 => 'This flight has already departed.',
            $isPaid && $hoursToDeparture !== null && $hoursToDeparture < $cutoff => sprintf('Online cancellation closes %d hours before departure. Please contact our support team.', $cutoff),
            default => null,
        };

        $quote = [
            'booking_reference' => $booking->booking_reference,
            'can_cancel' => $blockedReason === null,
            'blocked_reason' => $blockedReason,
            'is_paid' => $isPaid,
            'currency' => $booking->currency ?: 'INR',
            'original_amount' => (float) $booking->total_amount,
            'cancellation_fee' => 0.0,
            'refundable_amount' => 0.0,
            'applied_rule' => $isPaid ? null : 'Nothing has been paid for this booking, so there is no charge and no refund.',
            'breakdown' => [],
            'refund_processing_days' => (int) $policy['refund_processing_days'],
            'policy_text' => $policy['policy_notes'],
        ];

        if ($isPaid && $blockedReason === null) {
            $servicingQuote = app(FlightServicingService::class)->quoteCancellation($booking);
            $quote = array_merge($quote, Arr::only($servicingQuote, [
                'cancellation_fee', 'refundable_amount', 'applied_rule', 'breakdown', 'policy_text', 'refund_processing_days',
            ]));
        }

        return $quote;
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
            'avatar' => null,
        ];
    }
}
