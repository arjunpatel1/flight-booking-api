<?php

namespace Modules\Flight\Services\Payment;

use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Flight\Enums\BookingStatus;
use Modules\Flight\Enums\FlightPaymentMethod;
use Modules\Flight\Enums\FlightPaymentStatus;
use Modules\Flight\Enums\PaymentStatus;
use Modules\Flight\Events\FlightPaymentConfirmed;
use Modules\Flight\Exceptions\InsufficientBalanceException;
use Modules\Flight\Models\FlightAgent;
use Modules\Flight\Models\FlightB2BWallet;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightDistributor;
use Modules\Flight\Models\FlightPayment;
use Modules\Flight\Models\FlightHold;
use Modules\Flight\Enums\HoldStatus;
use Modules\Flight\Services\Hold\FlightHoldService;
use Modules\Flight\Services\Wallet\FlightWalletLedgerService;
use Modules\Saas\Models\Tenant;
use Modules\User\Models\User;

class FlightPaymentService
{
    public function __construct(
        protected FlightPaymentGatewayAdapter $gatewayAdapter,
        protected FlightWalletLedgerService $walletService,
        protected FlightHoldService $holdService
    ) {
    }

    /**
     * Initiate an online gateway payment session for a flight booking (B2C / Gateway).
     */
    public function initiatePayment(
        string|FlightBooking $bookingIdentifier,
        array $params = [],
        ?Tenant $tenant = null,
        ?User $user = null
    ): array {
        $booking = $this->resolveBooking($bookingIdentifier, $tenant);

        // A flight of a round trip / multi-city trip is never paid on its own:
        // that would charge one flight and leave the others unpaid.
        if ($booking->trip_reference) {
            return $this->initiateTripPayment($booking, $params, $user);
        }

        // 1. Verify hold & quote validity
        $this->verifyBookingPayability($booking);

        // A new attempt after a failed one is pending again until it has an outcome.
        if ($booking->payment_status === PaymentStatus::FAILED) {
            $booking->update(['payment_status' => PaymentStatus::PENDING]);
        }

        // Keep the reservation alive while the customer is in the external
        // checkout window. The browser countdown is paused during this period.
        $hold = $booking->hold_id
            ? FlightHold::where('id', $booking->hold_id)->orWhere('hold_id', $booking->hold_reference)->first()
            : null;
        if ($hold && $hold->status === HoldStatus::ACTIVE) {
            $paymentHoldSeconds = max(900, (int) config('flight.payment_hold_ttl', 1800));
            $hold->expires_at = Carbon::now()->addSeconds($paymentHoldSeconds);
            $hold->save();

            // A retry after checkout dismissal must keep the immutable fare and
            // its linked seat reservation valid for the same payment window.
            $quote = $booking->quote;
            if ($quote && (string) $quote->status === 'ACTIVE') {
                $quote->expires_at = $hold->expires_at;
                $quote->save();
            }
        }

        // 2. Authoritative amount from booking & confirmed ancillaries (NEVER trust frontend)
        $payableAmount = $this->calculateAuthoritativeAmount($booking);
        $currency = $booking->currency ?: 'INR';
        $existingGateway = ($params['idempotency_key'] ?? null) ? FlightPayment::where('flight_booking_id', $booking->id)
            ->where('idempotency_key', $params['idempotency_key'])
            ->whereNotIn('status', [FlightPaymentStatus::FAILED, FlightPaymentStatus::CANCELLED])
            ->value('gateway') : null;
        $gateway = $this->resolveGateway((int) $booking->tenant_id, $params, $existingGateway);

        // 3. Idempotency Check
        $idempotencyKey = $params['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $existing = FlightPayment::where('flight_booking_id', $booking->id)
                ->where('idempotency_key', $idempotencyKey)
                ->whereNotIn('status', [FlightPaymentStatus::FAILED, FlightPaymentStatus::CANCELLED])
                ->first();
            if ($existing && $this->retireExpiredUpi(collect([$existing]))) {
                $existing = null;
            }

            if ($existing) {
                if (strtolower($existing->gateway) !== $gateway) {
                    throw new DomainException('This idempotency key is already associated with another payment gateway.');
                }
                return [
                    'payment' => $existing,
                    'session' => [
                        'payment_reference' => $existing->payment_reference,
                        'gateway'           => $existing->gateway,
                        'gateway_order_id'  => $existing->gateway_order_id,
                        'amount'            => $existing->amount,
                        'currency'          => $existing->currency,
                        'key_id'            => $existing->gateway_response_metadata['key_id'] ?? 'mock_key',
                        'hold_expires_at'    => $hold?->expires_at?->toIso8601String(),
                        'hold_remaining_seconds' => $hold?->remaining_seconds,
                    ] + $this->upiSession($existing),
                ];
            }
        }

        // 4. Create Flight Payment record in INITIATED state
        $method = $params['payment_method'] ?? FlightPaymentMethod::GATEWAY->value;

        $payment = FlightPayment::create([
            'tenant_id'             => $booking->tenant_id,
            'flight_booking_id'     => $booking->id,
            'flight_fare_quote_id'  => $booking->quote_id,
            'flight_hold_id'        => $booking->hold_id,
            'user_id'               => $user?->id ?? $booking->user_id,
            'payer_type'            => 'CUSTOMER',
            'payer_id'              => $user?->id ?? $booking->user_id,
            'payment_method'        => $method,
            'gateway'               => $gateway,
            'idempotency_key'       => $idempotencyKey,
            'amount'                => $payableAmount,
            'currency'              => $currency,
            'status'                => FlightPaymentStatus::INITIATED,
            'initiated_at'          => now(),
        ]);

        // 5. Generate Upstream Gateway Order
        try {
            $orderData = $gateway === 'direct_upi'
                ? $this->directUpi()->createOrder($payment, (float) $payment->amount, $this->upiMinutes($hold?->expires_at))
                : $this->gatewayAdapter->createOrder($payment);
        } catch (\Throwable $error) {
            $payment->transitionTo(FlightPaymentStatus::FAILED, [
                'failure_reason' => 'Payment gateway order could not be created.',
            ]);
            throw $error;
        }
        $payment->update([
            'gateway_order_id'          => $orderData['gateway_order_id'] ?? null,
            'gateway_response_metadata' => $orderData,
        ]);

        event(new \Modules\Flight\Events\FlightPaymentInitiated($payment, $booking));

        return [
            'payment' => $payment->refresh(),
            'session' => [
                'payment_reference' => $payment->payment_reference,
                'gateway'           => $orderData['gateway'] ?? $gateway,
                'gateway_order_id'  => $orderData['gateway_order_id'] ?? null,
                'amount'            => $payment->amount,
                'currency'          => $payment->currency,
                'key_id'            => $orderData['key_id'] ?? null,
                'hold_expires_at'   => $hold?->expires_at?->toIso8601String(),
                'hold_remaining_seconds' => $hold?->remaining_seconds,
            ] + $this->upiSession($payment),
        ];
    }

    // ── Trips: several flights, one gateway payment ─────────────────────────
    //
    // Every flight of a trip keeps its own payment row with its own share of
    // the amount (so cancelling one flight refunds just that share), and all
    // of them carry the same gateway order, which is for the trip total.
    // Confirming, failing, the webhook and staff "Check payment" always act on
    // the whole set: every flight is paid, or none is.

    /** @return \Illuminate\Support\Collection<int, FlightBooking> the trip's flights in travel order */
    protected function tripBookings(FlightBooking $booking): \Illuminate\Support\Collection
    {
        return FlightBooking::query()
            ->with(['hold', 'quote'])
            ->where('trip_reference', $booking->trip_reference)
            ->where('tenant_id', $booking->tenant_id)
            ->orderBy('trip_leg')
            ->orderBy('id')
            ->get();
    }

    /**
     * Payments paid together with this one (same trip and gateway order),
     * including itself. A single-flight payment is a set of one.
     *
     * @return \Illuminate\Support\Collection<int, FlightPayment>
     */
    protected function orderSiblings(FlightPayment $payment, bool $lock = false): \Illuminate\Support\Collection
    {
        if (! $payment->trip_reference || ! $payment->gateway_order_id) {
            return collect([$payment]);
        }

        $query = FlightPayment::query()
            ->where('trip_reference', $payment->trip_reference)
            ->where('gateway_order_id', $payment->gateway_order_id)
            ->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    /** What the gateway was asked to collect for this payment's order. */
    protected function orderTotal(\Illuminate\Support\Collection $payments): float
    {
        return round((float) $payments->sum(fn (FlightPayment $payment) => (float) $payment->amount), 2);
    }

    /** Keep a flight's hold (and its quote) alive while the customer is in checkout. */
    protected function extendHoldForCheckout(FlightBooking $booking): ?FlightHold
    {
        $hold = $booking->hold_id
            ? FlightHold::where('id', $booking->hold_id)->orWhere('hold_id', $booking->hold_reference)->first()
            : null;
        if ($hold && $hold->status === HoldStatus::ACTIVE) {
            $hold->expires_at = Carbon::now()->addSeconds(max(900, (int) config('flight.payment_hold_ttl', 1800)));
            $hold->save();

            $quote = $booking->quote;
            if ($quote && (string) $quote->status === 'ACTIVE') {
                $quote->expires_at = $hold->expires_at;
                $quote->save();
            }
        }

        return $hold;
    }

    protected function initiateTripPayment(FlightBooking $lead, array $params, ?User $user): array
    {
        $bookings = $this->tripBookings($lead);
        if ($bookings->count() < 2) {
            throw new DomainException('This trip is incomplete. Please search and book the flights again.');
        }

        $existingGateway = ($params['idempotency_key'] ?? null) ? FlightPayment::query()
            ->where('trip_reference', $lead->trip_reference)
            ->where('idempotency_key', $params['idempotency_key'])
            ->whereNotIn('status', [FlightPaymentStatus::FAILED, FlightPaymentStatus::CANCELLED])
            ->value('gateway') : null;
        $gateway = $this->resolveGateway((int) $lead->tenant_id, $params, $existingGateway);

        foreach ($bookings as $booking) {
            $this->verifyBookingPayability($booking);
            if ($booking->payment_status === PaymentStatus::FAILED) {
                $booking->update(['payment_status' => PaymentStatus::PENDING]);
            }
        }
        $holds = $bookings->map(fn (FlightBooking $booking) => $this->extendHoldForCheckout($booking))->filter();

        // Authoritative amounts from each booking (never from the browser).
        $amounts = $bookings->mapWithKeys(fn (FlightBooking $booking) => [$booking->id => $this->calculateAuthoritativeAmount($booking)]);
        $total = round((float) $amounts->sum(), 2);
        $currency = $lead->currency ?: 'INR';
        $holdSession = [
            'hold_expires_at'        => $holds->min(fn (FlightHold $hold) => $hold->expires_at)?->toIso8601String(),
            'hold_remaining_seconds' => $holds->min(fn (FlightHold $hold) => $hold->remaining_seconds),
        ];

        // A retry with the same key reuses the trip's open payment session.
        $idempotencyKey = $params['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $existing = FlightPayment::query()
                ->where('trip_reference', $lead->trip_reference)
                ->where('idempotency_key', $idempotencyKey)
                ->whereNotIn('status', [FlightPaymentStatus::FAILED, FlightPaymentStatus::CANCELLED])
                ->get();
            if ($existing->isNotEmpty() && $this->retireExpiredUpi($existing)) {
                $existing = collect();
            }
            $sameOrder = $existing->pluck('gateway_order_id')->filter()->unique();
            $sameAmounts = $existing->count() === $bookings->count()
                && $existing->every(fn (FlightPayment $payment) => abs((float) $payment->amount - (float) ($amounts[$payment->flight_booking_id] ?? -1)) < 0.01);
            if ($sameOrder->count() === 1 && $sameAmounts) {
                if ($existing->contains(fn (FlightPayment $payment) => strtolower($payment->gateway) !== $gateway)) {
                    throw new DomainException('This idempotency key is already associated with another payment gateway.');
                }
                $leadPayment = $existing->firstWhere('flight_booking_id', $lead->id) ?? $existing->first();

                return $this->tripPaymentResponse($leadPayment, $existing, $total, $holdSession);
            }
        }

        $method = $params['payment_method'] ?? FlightPaymentMethod::GATEWAY->value;
        $payments = DB::transaction(fn () => $bookings->map(fn (FlightBooking $booking) => FlightPayment::create([
            'tenant_id'            => $booking->tenant_id,
            'flight_booking_id'    => $booking->id,
            'trip_reference'       => $lead->trip_reference,
            'flight_fare_quote_id' => $booking->quote_id,
            'flight_hold_id'       => $booking->hold_id,
            'user_id'              => $user?->id ?? $booking->user_id,
            'payer_type'           => 'CUSTOMER',
            'payer_id'             => $user?->id ?? $booking->user_id,
            'payment_method'       => $method,
            'gateway'              => $gateway,
            'idempotency_key'      => $idempotencyKey,
            'amount'               => $amounts[$booking->id],
            'currency'             => $currency,
            'status'               => FlightPaymentStatus::INITIATED,
            'initiated_at'         => now(),
        ])));
        $leadPayment = $payments->firstWhere('flight_booking_id', $lead->id) ?? $payments->first();

        try {
            $orderData = $gateway === 'direct_upi'
                ? $this->directUpi()->createOrder($leadPayment, $total, $this->upiMinutes($holds->min(fn (FlightHold $hold) => $hold->expires_at)))
                : $this->gatewayAdapter->createOrder($leadPayment, $total);
        } catch (\Throwable $error) {
            $payments->each(fn (FlightPayment $payment) => $payment->transitionTo(FlightPaymentStatus::FAILED, [
                'failure_reason' => 'Payment gateway order could not be created.',
            ]));
            throw $error;
        }

        foreach ($payments as $payment) {
            $payment->update([
                'gateway_order_id'          => $orderData['gateway_order_id'] ?? null,
                'gateway_response_metadata' => array_merge($orderData, [
                    'trip_reference' => $lead->trip_reference,
                    'trip_total'     => $total,
                ]),
            ]);
        }
        foreach ($bookings as $booking) {
            event(new \Modules\Flight\Events\FlightPaymentInitiated($payments->firstWhere('flight_booking_id', $booking->id), $booking));
        }

        return $this->tripPaymentResponse($leadPayment->refresh(), $payments, $total, $holdSession);
    }

    private function tripPaymentResponse(FlightPayment $leadPayment, \Illuminate\Support\Collection $payments, float $total, array $holdSession): array
    {
        return [
            'payment'        => $leadPayment,
            'trip_reference' => $leadPayment->trip_reference,
            'payments'       => $payments->map(fn (FlightPayment $payment) => [
                'payment_reference' => $payment->payment_reference,
                'flight_booking_id' => $payment->flight_booking_id,
                'amount'            => (float) $payment->amount,
            ])->values(),
            'session'        => array_merge([
                // The customer pays once, through the trip's first flight.
                'payment_reference' => $leadPayment->payment_reference,
                'gateway'           => $leadPayment->gateway,
                'gateway_order_id'  => $leadPayment->gateway_order_id,
                'amount'            => $total,
                'currency'          => $leadPayment->currency,
                'key_id'            => $leadPayment->gateway_response_metadata['key_id'] ?? 'mock_key',
                'trip_reference'    => $leadPayment->trip_reference,
            ] + $this->upiSession($leadPayment), $holdSession),
        ];
    }

    /** A captured gateway payment: mark one flight's payment completed and its booking paid. */
    private function completeAndConfirm(FlightPayment $payment, string $gatewayPaymentId, array $completion): FlightBooking
    {
        if ($payment->status !== FlightPaymentStatus::COMPLETED) {
            $payment->transitionTo(FlightPaymentStatus::COMPLETED, array_merge(['gateway_transaction_id' => $gatewayPaymentId], $completion));
        }

        $booking = FlightBooking::whereKey($payment->flight_booking_id)->lockForUpdate()->firstOrFail();
        if ($booking->payment_status !== PaymentStatus::PAID) {
            $booking->update([
                'payment_status'    => PaymentStatus::PAID,
                'booking_status'    => $this->paidBookingStatus($booking),
                'payment_reference' => $payment->payment_reference,
                'confirmed_at'      => now(),
            ]);
            app(\Modules\Flight\Services\Booking\FlightOfferService::class)->redeemForBooking($booking);
            $this->holdService->convertForPaidBooking($booking);
            event(new FlightPaymentConfirmed($payment, $booking));
            // Ticket as soon as the payment is saved, not only when a webhook arrives.
            $bookingId = $booking->id;
            DB::afterCommit(fn () => $this->issueTicketAfterPayment($bookingId));
        }

        return $booking;
    }

    /**
     * Issue the e-ticket for a booking that was just paid. Runs after the
     * payment is committed, so a ticketing problem never undoes the payment:
     * it is logged on the booking (ticketing_error) for staff to retry.
     * Bookings still waiting for a manual PNR are ticketed when it is entered.
     */
    private function issueTicketAfterPayment(int $bookingId): void
    {
        $booking = FlightBooking::query()->withoutGlobalScopes()->find($bookingId);
        if (! $booking
            || $booking->payment_status !== PaymentStatus::PAID
            || $booking->isTicketed()
            || $booking->booking_status === BookingStatus::PNR_PENDING
            || $booking->booking_status === BookingStatus::CANCELLED) {
            return;
        }

        try {
            app(\Modules\Flight\Services\Ticketing\FlightTicketingService::class)->issueTicket($booking);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('FlightPaymentService: automatic ticketing after payment failed', [
                'booking' => $booking->booking_reference,
                'error'   => $e->getMessage(),
            ]);
            $booking->forceFill(['ticketing_error' => 'AUTO_TICKETING_FAILED: '.$e->getMessage()])->save();
        }
    }

    /**
     * Verify online payment callback and confirm booking atomically.
     */
    public function verifyPayment(
        string $paymentReference,
        array $payload,
        ?Tenant $tenant = null
    ): FlightPayment {
        return DB::transaction(function () use ($paymentReference, $payload, $tenant) {
            $payment = FlightPayment::where('payment_reference', $paymentReference)
                ->lockForUpdate()
                ->firstOrFail();

            if ($tenant && $payment->tenant_id && $payment->tenant_id !== $tenant->id) {
                throw new DomainException('Tenant mismatch on payment verification.');
            }

            // Idempotent completion check
            if ($payment->status === FlightPaymentStatus::COMPLETED) {
                return $payment;
            }

            // A trip's flights share this gateway order: they are paid, or failed, together.
            $siblings = $this->orderSiblings($payment, true);
            $booking = FlightBooking::whereKey($payment->flight_booking_id)->lockForUpdate()->firstOrFail();
            $failAll = function (string $reason) use ($siblings): void {
                foreach ($siblings as $sibling) {
                    if ($sibling->status->canTransitionTo(FlightPaymentStatus::FAILED) && $sibling->status !== FlightPaymentStatus::FAILED) {
                        $sibling->transitionTo(FlightPaymentStatus::FAILED, ['failure_reason' => $reason]);
                    }
                    $siblingBooking = FlightBooking::find($sibling->flight_booking_id);
                    if ($siblingBooking) {
                        event(new \Modules\Flight\Events\FlightPaymentFailed($sibling, $siblingBooking, $reason));
                    }
                }
            };

            // Validate gateway order ID matches
            $givenOrderId = $payload['razorpay_order_id'] ?? $payload['order_id'] ?? null;
            $isMockGateway = strtolower($payment->gateway ?: '') === 'mock';
            if ($isMockGateway && ! app()->environment('local', 'testing')) {
                throw new DomainException('Mock payment verification is disabled outside local and test environments.');
            }
            if (! $payment->gateway_order_id || ! $givenOrderId || ! hash_equals((string) $payment->gateway_order_id, (string) $givenOrderId)) {
                $failAll('Gateway order ID mismatch.');
                throw new DomainException('Gateway order ID does not match payment session.');
            }

            // Validate Signature
            $isValid = $this->gatewayAdapter->verifySignature($payment, $payload);
            if (! $isValid) {
                $failAll('Payment signature verification failed.');
                throw new DomainException('Payment signature verification failed.');
            }

            // The gateway must have collected the whole order (the trip total for a trip).
            if (! $this->gatewayAdapter->verifyCapturedPayment($payment, $payload, $this->orderTotal($siblings))) {
                throw new DomainException('Razorpay has not captured this payment yet. Please check the payment status before retrying.');
            }

            $gatewayPaymentId = $payload['razorpay_payment_id'] ?? $payload['payment_id'] ?? ('PAY-' . Str::random(12));
            $reconciliation = 'REC-' . strtoupper(Str::random(10));

            // Mark every payment COMPLETED and confirm every booking it pays for.
            foreach ($siblings as $sibling) {
                $this->completeAndConfirm($sibling, (string) $gatewayPaymentId, [
                    'reconciliation_reference'  => $reconciliation,
                    'gateway_response_metadata' => array_merge($sibling->gateway_response_metadata ?? [], $payload),
                ]);
            }

            return $payment->refresh();
        });
    }

    /**
     * Staff "Check Payment": the booking becomes paid only if Razorpay itself says
     * the money arrived. Covers a customer who paid but whose browser closed before
     * the verify call, when no webhook arrived either.
     *
     * @return array{paid: bool, booking: FlightBooking}
     */
    public function confirmFromGateway(string|FlightBooking $identifier, ?Tenant $tenant = null): array
    {
        $booking = $this->resolveBooking($identifier, $tenant);

        if ($booking->payment_status === PaymentStatus::PAID) {
            return ['paid' => true, 'booking' => $booking];
        }
        if (in_array($booking->booking_status, [BookingStatus::CANCELLED, BookingStatus::FAILED, BookingStatus::COMPLETED], true)) {
            throw new DomainException("A {$booking->booking_status->value} booking cannot be marked as paid.");
        }

        $upiPayments = FlightPayment::where('flight_booking_id', $booking->id)
            ->where('gateway', 'direct_upi')->whereNotNull('gateway_order_id')->latest('id')->get();
        foreach ($upiPayments as $upiPayment) {
            if ($this->syncDirectUpiPayment($upiPayment)->status === FlightPaymentStatus::COMPLETED) {
                return ['paid' => true, 'booking' => $booking->refresh()];
            }
        }

        $payments = FlightPayment::where('flight_booking_id', $booking->id)
            ->where('gateway', 'razorpay')
            ->whereNotNull('gateway_order_id')
            ->latest('id')
            ->get();

        foreach ($payments as $payment) {
            // A trip's order is for the whole trip, so that is the amount to find.
            $gatewayPaymentId = $this->gatewayAdapter->findCapturedPaymentId($payment, $this->orderTotal($this->orderSiblings($payment)));
            if ($gatewayPaymentId) {
                $booking = $this->completeCheckedPayment($payment, $gatewayPaymentId);

                return ['paid' => true, 'booking' => $booking];
            }
        }

        return ['paid' => false, 'booking' => $booking];
    }

    private function completeCheckedPayment(FlightPayment $payment, string $gatewayPaymentId): FlightBooking
    {
        return DB::transaction(function () use ($payment, $gatewayPaymentId) {
            $payment = FlightPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $booking = FlightBooking::whereKey($payment->flight_booking_id)->lockForUpdate()->firstOrFail();

            // The customer's verify call or a webhook may have finished it meanwhile.
            if ($booking->payment_status === PaymentStatus::PAID) {
                return $booking;
            }

            // Every flight paid by this order (just this one outside a trip).
            $reconciliation = 'CHECK-' . strtoupper(Str::random(8));
            foreach ($this->orderSiblings($payment, true) as $sibling) {
                $this->completeAndConfirm($sibling, $gatewayPaymentId, ['reconciliation_reference' => $reconciliation]);
            }

            return $booking->refresh();
        });
    }

    /**
     * B2B Wallet Deduction Payment: Atomically debit B2B wallet, log double-entry ledger & confirm booking.
     */
    public function payWithWallet(
        string|FlightBooking $bookingIdentifier,
        array $params = [],
        ?Tenant $tenant = null,
        ?User $user = null
    ): FlightPayment {
        $booking = $this->resolveBooking($bookingIdentifier, $tenant);

        if ($booking->trip_reference) {
            throw new DomainException('This flight is part of a trip, which is paid online in one payment.');
        }

        // 1. Idempotency Check: if already completed with this key, return existing payment
        $idempotencyKey = $params['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $existing = FlightPayment::where('flight_booking_id', $booking->id)
                ->where('idempotency_key', $idempotencyKey)
                ->where('status', FlightPaymentStatus::COMPLETED)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // 2. Verify hold & quote validity
        $this->verifyBookingPayability($booking);

        // 3. Authoritative amount
        $payableAmount = $this->calculateAuthoritativeAmount($booking);
        $currency = $booking->currency ?: 'INR';

        // 4. Resolve Wallet
        $wallet = $this->resolveB2BWallet($booking, $params, $user);

        // 5. Execute Atomic DB Transaction with Row Locking
        try {
            return DB::transaction(function () use ($booking, $wallet, $payableAmount, $currency, $idempotencyKey, $user) {
                $lockedBooking = FlightBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
                $lockedWallet = FlightB2BWallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();

                // Check if already paid
                if ($lockedBooking->payment_status === PaymentStatus::PAID) {
                    throw new DomainException('Booking is already paid.');
                }

                // Check distributor / agent active status (suspension check)
                $this->assertWalletOwnerIsActive($lockedWallet);

                // Check available purchasing power: (balance + credit_limit) - credit_used
                if ($lockedWallet->available_credit < $payableAmount) {
                    throw new InsufficientBalanceException(
                        requestedAmount: $payableAmount,
                        availableCredit: $lockedWallet->available_credit,
                        balance: (float) $lockedWallet->balance,
                        creditLimit: (float) $lockedWallet->credit_limit
                    );
                }

                // Deduct via Double-Entry Ledger Service: DEBIT Wallet -> CREDIT Escrow
                $actor = $user?->email ?? ('USER-' . ($user?->id ?? 'SYSTEM'));
                $walletTxn = $this->walletService->bookingDeduction(
                    wallet: $lockedWallet,
                    amount: $payableAmount,
                    bookingReference: $lockedBooking->booking_reference,
                actor: $actor,
                metadata: [
                    'booking_id'      => $lockedBooking->id,
                    'tenant_id'       => $lockedBooking->tenant_id,
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            // Record Flight Payment Transaction
            $payment = FlightPayment::create([
                'tenant_id'                 => $lockedBooking->tenant_id,
                'flight_booking_id'         => $lockedBooking->id,
                'flight_fare_quote_id'      => $lockedBooking->quote_id,
                'flight_hold_id'            => $lockedBooking->hold_id,
                'user_id'                   => $user?->id ?? $lockedBooking->user_id,
                'payer_type'                => is_object($lockedWallet->owner_type) ? $lockedWallet->owner_type->value : (string) $lockedWallet->owner_type,
                'payer_id'                  => $lockedWallet->owner_id,
                'payment_method'            => FlightPaymentMethod::WALLET->value,
                'gateway'                   => 'wallet',
                'gateway_transaction_id'    => $walletTxn->transaction_id,
                'reconciliation_reference'  => $walletTxn->transaction_id,
                'idempotency_key'           => $idempotencyKey,
                'amount'                    => $payableAmount,
                'currency'                  => $currency,
                'status'                    => FlightPaymentStatus::COMPLETED,
                'initiated_at'              => now(),
                'completed_at'              => now(),
                'gateway_response_metadata' => [
                    'wallet_account' => $lockedWallet->account_number ?? ($lockedWallet->account_identifier ?? 'WALLET'),
                    'transaction_id' => $walletTxn->transaction_id,
                ],
            ]);

            // Update Flight Booking payment & booking status
            $lockedBooking->update([
                'payment_status'    => PaymentStatus::PAID,
                'booking_status'    => $this->paidBookingStatus($lockedBooking),
                'payment_reference' => $payment->payment_reference,
                'confirmed_at'      => now(),
            ]);
            app(\Modules\Flight\Services\Booking\FlightOfferService::class)->redeemForBooking($lockedBooking);
            // The seats are sold now. Without this the hold stayed ACTIVE, its
            // timer ran out, and expiry put the paid seat back on sale.
            $this->holdService->convertForPaidBooking($lockedBooking);

            // Emit Domain Event
            event(new FlightPaymentConfirmed($payment, $lockedBooking));
            // A wallet payment has no gateway webhook to issue the ticket, so it
            // used to sit "ticket pending" until someone downloaded it.
            $bookingId = $lockedBooking->id;
            DB::afterCommit(fn () => $this->issueTicketAfterPayment($bookingId));

            return $payment->refresh();
        });
    } catch (InsufficientBalanceException $e) {
        $failedPayment = FlightPayment::create([
            'tenant_id'                 => $booking->tenant_id,
            'flight_booking_id'         => $booking->id,
            'flight_fare_quote_id'      => $booking->quote_id,
            'flight_hold_id'            => $booking->hold_id,
            'user_id'                   => $user?->id ?? $booking->user_id,
            'payer_type'                => is_object($wallet->owner_type) ? $wallet->owner_type->value : (string) $wallet->owner_type,
            'payer_id'                  => $wallet->owner_id,
            'payment_method'            => FlightPaymentMethod::WALLET->value,
            'gateway'                   => 'wallet',
            'amount'                    => $payableAmount,
            'currency'                  => $currency,
            'status'                    => FlightPaymentStatus::FAILED,
            'failure_reason'            => 'Insufficient wallet credit',
            'failed_at'                 => now(),
        ]);

        event(new \Modules\Flight\Events\FlightPaymentFailed($failedPayment, $booking, 'Insufficient wallet balance or credit limit.'));

        throw $e;
    }
}

    /**
     * Webhook Processor for Payment Gateways.
     */
    public function processWebhook(string $gateway, array $payload, string $rawBody, ?string $signature = null): array
    {
        // 1. Verify Webhook Signature
        $isLocalMockWebhook = strtolower($gateway) === 'mock' && app()->environment('local', 'testing');
        if (! $isLocalMockWebhook
            && (! $signature || ! $this->gatewayAdapter->verifyWebhookSignature($rawBody, $signature))) {
            throw new DomainException('Missing or invalid payment webhook signature.');
        }

        $event = $payload['event'] ?? 'payment.captured';

        // Refund lifecycle (refund.created / refund.processed / refund.failed)
        // confirms that money sent back through Razorpay actually settled.
        if (str_starts_with((string) $event, 'refund.')) {
            $refundEntity = (array) data_get($payload, 'payload.refund.entity', []);
            if ($refundEntity === []) {
                return ['status' => 'ignored', 'message' => 'Refund webhook without a refund entity.'];
            }

            return app(\Modules\Flight\Services\Servicing\FlightServicingService::class)
                ->applyGatewayRefundEvent((string) $event, $refundEntity);
        }

        // A reschedule's extra charge was paid through a Razorpay payment link.
        if (str_starts_with((string) $event, 'payment_link.')) {
            return app(\Modules\Flight\Services\Servicing\FlightServicingService::class)
                ->applyPaymentLinkEvent((string) $event, $payload);
        }

        $entity = data_get($payload, 'payload.payment.entity') ?? $payload;
        $orderId = $entity['order_id'] ?? ($payload['order_id'] ?? null);
        $paymentId = $entity['id'] ?? ($payload['payment_id'] ?? null);

        if (empty($orderId) && empty($paymentId)) {
            return ['status' => 'ignored', 'message' => 'No order or payment identifier in webhook.'];
        }

        return DB::transaction(function () use ($gateway, $orderId, $paymentId, $event, $payload) {
            $payment = FlightPayment::where('gateway_order_id', $orderId)
                ->orWhere('gateway_transaction_id', $paymentId)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                return ['status' => 'not_found', 'message' => 'No matching flight payment for webhook.'];
            }
            if (strtolower($payment->gateway) !== strtolower($gateway)) {
                throw new DomainException('Payment webhook gateway does not match the payment session.');
            }
            // Settled by the payment_link.* event, not by this payment's own event.
            if (($payment->gateway_response_metadata['type'] ?? null) === 'RESCHEDULE_CHARGE') {
                return ['status' => 'ignored', 'message' => 'Reschedule charges are settled by the payment link event.'];
            }
            // A trip's flights share this order; the gateway collected their total.
            $siblings = $this->orderSiblings($payment, true);
            $entity = data_get($payload, 'payload.payment.entity') ?? $payload;
            if (in_array($event, ['payment.captured', 'order.paid'], true)
                && (($entity['order_id'] ?? null) !== $payment->gateway_order_id
                    || (int) ($entity['amount'] ?? 0) !== (int) round($this->orderTotal($siblings) * 100)
                    || strtoupper((string) ($entity['currency'] ?? '')) !== strtoupper((string) $payment->currency))) {
                throw new DomainException('Payment webhook details do not match the flight payment.');
            }

            // Idempotent duplicate check
            if ($siblings->every(fn (FlightPayment $sibling) => $sibling->status === FlightPaymentStatus::COMPLETED)) {
                return ['status' => 'already_completed', 'reference' => $payment->payment_reference];
            }

            if (in_array($event, ['payment.captured', 'order.paid'], true)) {
                $reconciliation = 'WEBHOOK-' . strtoupper(Str::random(8));
                foreach ($siblings as $sibling) {
                    $this->completeAndConfirm($sibling, (string) ($paymentId ?? $sibling->gateway_transaction_id), [
                        'reconciliation_reference'  => $reconciliation,
                        'gateway_response_metadata' => array_merge($sibling->gateway_response_metadata ?? [], $payload),
                    ]);
                }

                return ['status' => 'completed', 'reference' => $payment->payment_reference];
            }

            if (in_array($event, ['payment.failed'], true)) {
                foreach ($siblings as $sibling) {
                    if ($sibling->status->canTransitionTo(FlightPaymentStatus::FAILED) && $sibling->status !== FlightPaymentStatus::FAILED) {
                        $sibling->transitionTo(FlightPaymentStatus::FAILED, [
                            'failure_reason' => $payload['error']['description'] ?? 'Webhook reported payment failed.',
                        ]);
                    }
                    $this->markBookingPaymentFailed($sibling);
                }

                return ['status' => 'marked_failed', 'reference' => $payment->payment_reference];
            }

            return ['status' => 'processed', 'event' => $event];
        });
    }

    /**
     * The gateway's checkout told the customer's browser that an attempt failed.
     * Webhooks report this too, but they may arrive late or not be configured.
     */
    public function recordPaymentFailure(string $paymentReference, ?string $reason = null, ?Tenant $tenant = null): FlightPayment
    {
        return DB::transaction(function () use ($paymentReference, $reason, $tenant) {
            $payment = FlightPayment::where('payment_reference', $paymentReference)
                ->lockForUpdate()
                ->firstOrFail();

            if ($tenant && $payment->tenant_id && $payment->tenant_id !== $tenant->id) {
                throw new DomainException('Tenant mismatch on payment failure.');
            }

            // A trip fails as a whole: every flight's seats go back on sale. A retry can still pay.
            foreach ($this->orderSiblings($payment, true) as $sibling) {
                if ($sibling->status->canTransitionTo(FlightPaymentStatus::FAILED) && $sibling->status !== FlightPaymentStatus::FAILED) {
                    $sibling->transitionTo(FlightPaymentStatus::FAILED, [
                        'failure_reason' => $reason ?: 'Customer checkout reported payment failed.',
                    ]);
                }
                if ($sibling->status === FlightPaymentStatus::FAILED) {
                    $this->markBookingPaymentFailed($sibling);
                }
            }

            return $payment->refresh();
        });
    }

    /** Show the failure on the booking and put its held seats back on sale. A retry can still pay. */
    private function markBookingPaymentFailed(FlightPayment $payment): void
    {
        $booking = FlightBooking::whereKey($payment->flight_booking_id)->lockForUpdate()->first();
        if (! $booking || in_array($booking->payment_status, [PaymentStatus::PAID, PaymentStatus::REFUNDED], true)) {
            return;
        }

        $booking->update(['payment_status' => PaymentStatus::FAILED]);

        if ($booking->hold_id || $booking->hold_reference) {
            $hold = FlightHold::where('id', $booking->hold_id)
                ->orWhere('hold_id', $booking->hold_reference)
                ->lockForUpdate()
                ->first();
            if ($hold && $hold->status === HoldStatus::ACTIVE) {
                app(\Modules\Flight\Services\Hold\FlightHoldService::class)
                    ->releaseHold($hold->id, 'Payment failed');
            }
        }
    }

    /**
     * Retrieve payment details for status polling.
     */
    public function getPayment(string $reference, ?Tenant $tenant = null): FlightPayment
    {
        $query = FlightPayment::with(['booking', 'booking.passengers', 'booking.ancillaries'])
            ->where('payment_reference', $reference);

        if ($tenant) {
            $query->where('tenant_id', $tenant->id);
        }

        $payment = $query->firstOrFail();
        // While the customer's UPI QR is open, each status check asks the gateway
        // (at most once every 3 seconds per payment).
        if (strtolower((string) $payment->gateway) === 'direct_upi' && $payment->status !== FlightPaymentStatus::COMPLETED
            && \Illuminate\Support\Facades\Cache::add('flight-upi-sync:'.$payment->id, 1, 3)) {
            $this->syncDirectUpiPayment($payment);
            $payment = (clone $query)->firstOrFail();
        }

        return $payment;
    }

    /**
     * Get payment options, breakdown, and wallet info for a booking.
     */
    public function getPaymentOptions(string|FlightBooking $bookingIdentifier, ?Tenant $tenant = null, ?User $user = null): array
    {
        $booking = $this->resolveBooking($bookingIdentifier, $tenant);
        $amount = $this->calculateAuthoritativeAmount($booking);

        $walletInfo = null;
        try {
            $wallet = $this->resolveB2BWallet($booking, [], $user);
            if ($wallet) {
                $walletInfo = [
                    'wallet_id'        => $wallet->id,
                    'account_number'   => $wallet->account_number,
                    // owner_type is stored as a plain string on some wallets;
                    // ->value on it threw and silently hid the wallet option.
                    'owner_type'       => is_object($wallet->owner_type) ? $wallet->owner_type->value : (string) $wallet->owner_type,
                    'currency'         => $wallet->currency,
                    'balance'          => (float) $wallet->balance,
                    'credit_limit'     => (float) $wallet->credit_limit,
                    'credit_used'      => (float) $wallet->credit_used,
                    'available_credit' => (float) $wallet->available_credit,
                    'is_active'        => (bool) $wallet->is_active,
                    'can_pay'          => $wallet->is_active && ($wallet->available_credit >= $amount),
                ];
            }
        } catch (\Throwable) {
            // User does not have a B2B wallet, fallback to gateway only
        }

        $availableGateways = [];
        if ($this->gatewayAdapter->isRazorpayConfigured()) {
            $availableGateways[] = [
                'id' => 'razorpay',
                'name' => 'Razorpay',
                'key_id' => config('payment.gateways.razorpay.key_id'),
            ];
        }
        if (app()->environment('local', 'testing')) {
            $availableGateways[] = ['id' => 'mock', 'name' => 'Mock Gateway (Test Driver)', 'key_id' => null];
        }

        $pricing = (array) ($booking->pricing_breakdown ?? []);
        $ancillariesAmount = (float) $booking->ancillaries()->sum('price');
        $itinerary = (array) ($booking->itinerary ?? []);
        $notPayableReason = $this->paymentOptionsBlocker($booking);

        return [
            'booking_reference'   => $booking->booking_reference,
            'booking_status'      => $booking->booking_status->value,
            'payment_status'      => $booking->payment_status->value,
            'total_amount'        => $amount,
            'payable_amount'      => $amount,
            'currency'            => $booking->currency ?: 'INR',
            // Checkout pages must not offer payment for cancelled/failed bookings
            // or an expired hold; initiatePayment() enforces the same rules.
            'is_payable'          => $notPayableReason === null,
            'not_payable_reason'  => $notPayableReason,
            'pricing_breakdown'   => $booking->pricing_breakdown,
            // Shape expected by the checkout pages (FlightPaymentOptions.fare_breakdown).
            'fare_breakdown'      => [
                'base_fare'         => (float) ($pricing['base_fare'] ?? $pricing['supplier_fare'] ?? 0),
                'taxes'             => (float) ($pricing['taxes'] ?? 0) + (float) ($pricing['gst'] ?? 0),
                'markup'            => (float) ($pricing['client_markup'] ?? 0) + (float) ($pricing['tenant_markup'] ?? 0) + (float) ($pricing['platform_markup'] ?? 0),
                'convenience_fee'   => (float) ($pricing['convenience_fee'] ?? 0),
                'discount'          => (float) ($pricing['discount'] ?? 0),
                'ancillaries_total' => $ancillariesAmount,
            ],
            'itinerary'           => [
                'flight_number'     => $itinerary['flight_number'] ?? null,
                'airline_name'      => data_get($itinerary, 'airline.name') ?: data_get($itinerary, 'airline.code'),
                'cabin_class'       => $itinerary['cabin_class'] ?? null,
                'departure_airport' => data_get($itinerary, 'origin.code'),
                'departure_city'    => data_get($itinerary, 'origin.city'),
                'departure_time'    => trim((data_get($itinerary, 'departure.date') ?: data_get($itinerary, 'departure_date') ?: '').' '.substr((string) (data_get($itinerary, 'departure.time') ?: ''), 0, 5)) ?: null,
                'arrival_airport'   => data_get($itinerary, 'destination.code'),
                'arrival_city'      => data_get($itinerary, 'destination.city'),
                'arrival_time'      => is_string($itinerary['arrival_time'] ?? null) ? preg_replace('/(\d{2}:\d{2}):\d{2}$/', '$1', $itinerary['arrival_time']) : null,
            ],
            'passengers'          => $booking->passengers()->get(['passenger_type', 'first_name', 'last_name'])
                ->map(fn ($p) => ['type' => is_object($p->passenger_type) ? $p->passenger_type->value : $p->passenger_type, 'name' => trim("{$p->first_name} {$p->last_name}")])
                ->values(),
            'ancillaries_amount'  => $ancillariesAmount,
            'available_gateways'  => $availableGateways,
            'gateways'            => array_column($availableGateways, 'id'),
            'b2b_wallet'          => $walletInfo,
            'wallet'              => $walletInfo,
        ];
    }

    /**
     * Why a booking cannot be paid right now, mirroring verifyBookingPayability()
     * without its side effects (that method may renew the fare quote). A paid
     * booking returns null: the checkout page shows it as completed instead.
     */
    protected function paymentOptionsBlocker(FlightBooking $booking): ?string
    {
        $bookingStatus = strtoupper(is_object($booking->booking_status) ? $booking->booking_status->value : (string) $booking->booking_status);
        $paymentStatus = strtoupper(is_object($booking->payment_status) ? $booking->payment_status->value : (string) $booking->payment_status);
        if ($paymentStatus === 'PAID') {
            return null;
        }
        if ($bookingStatus === 'CANCELLED') {
            return 'This booking was cancelled before it was paid, so it can no longer be paid. Create a new booking instead.';
        }
        if ($bookingStatus === 'FAILED') {
            return 'This booking failed and can no longer be paid. Create a new booking instead.';
        }
        if ($booking->hold && $booking->hold->isExpired()) {
            return 'The seat hold for this booking has expired, so it can no longer be paid. Search again to book this flight.';
        }

        return null;
    }

    // ── Helper Verification Methods ──────────────────────────────────────────

    protected function resolveBooking(string|FlightBooking $identifier, ?Tenant $tenant = null): FlightBooking
    {
        if ($identifier instanceof FlightBooking) {
            return $identifier->refresh();
        }

        // Grouped: otherwise "ref = X OR id = Y AND tenant = Z" would let a booking
        // reference from another tenant bypass the tenant filter.
        $query = FlightBooking::where(function ($match) use ($identifier) {
            $match->where('booking_reference', $identifier)
                ->orWhere('id', is_numeric($identifier) ? (int) $identifier : 0);
        });

        if ($tenant) {
            $query->where('tenant_id', $tenant->id);
        }

        return $query->firstOrFail();
    }

    protected function verifyBookingPayability(FlightBooking $booking): void
    {
        $paymentStatus = is_object($booking->payment_status) ? $booking->payment_status->value : (string) $booking->payment_status;
        if (strtoupper($paymentStatus) === 'PAID') {
            throw new DomainException('Booking is already paid.');
        }

        $bookingStatus = is_object($booking->booking_status) ? $booking->booking_status->value : (string) $booking->booking_status;
        if (in_array(strtoupper($bookingStatus), ['CANCELLED', 'FAILED'], true)) {
            throw new DomainException("Cannot pay for booking with status [{$bookingStatus}].");
        }

        // Verify hold validity if present
        if ($booking->hold && $booking->hold->isExpired()) {
            throw new DomainException('Flight inventory hold has expired. Please initiate a new search.');
        }

        // A payment attempt can extend a live hold while a customer is in the
        // gateway. If the linked quote elapsed during a dismissed checkout,
        // only renew it when the stored fare still matches current pricing.
        $quote = $booking->quote;
        if ($quote && $quote->isExpired()) {
            $hold = $booking->hold;
            if (! $hold || ! $hold->isActive() || ! $quote->flight_fare_id) {
                throw new DomainException('Flight fare quote has expired. Fares may have changed. Please select the flight again.');
            }

            $quantity = max(1, (int) data_get($quote->selected_itinerary, 'quantity', 1));
            $priceCheck = $this->holdService->revalidatePrice([
                'fare_id' => $quote->flight_fare_id,
                'expected_price' => (float) $quote->customer_price,
                'quantity' => $quantity,
            ], $booking->tenant);

            if (empty($priceCheck['available']) || ! empty($priceCheck['price_changed'])) {
                throw new DomainException('Flight fare quote expired and the fare has changed. Please search again and confirm the new fare.');
            }

            $quote->status = 'ACTIVE';
            $quote->expires_at = $hold->expires_at;
            $quote->save();
        }
    }

    public function calculateAuthoritativeAmount(FlightBooking $booking): float
    {
        $baseTotal = (float) $booking->total_amount;
        $ancillariesTotal = (float) $booking->ancillaries()->sum('price');

        // If booking total already contains ancillaries, use booking total; otherwise add ancillaries
        $total = $baseTotal;
        if ($ancillariesTotal > 0 && ! str_contains(json_encode($booking->pricing_breakdown ?? []), 'ancillaries')) {
            $total += $ancillariesTotal;
        }

        return round(max(0, $total), 2);
    }

    protected function resolveB2BWallet(FlightBooking $booking, array $params = [], ?User $user = null): FlightB2BWallet
    {
        if (! empty($params['wallet_id'])) {
            return FlightB2BWallet::findOrFail((int) $params['wallet_id']);
        }

        // Check if user is associated with Agent
        $agent = FlightAgent::where('user_id', $user?->id ?? 0)->first();
        if ($agent) {
            return $this->walletService->getOrCreateAgentWallet($agent);
        }

        // Check if user is associated with Distributor
        $dist = FlightDistributor::where('user_id', $user?->id ?? 0)->first();
        if ($dist) {
            return $this->walletService->getOrCreateDistributorWallet($dist);
        }

        // Fallback to Tenant wallet
        $tenantId = $booking->tenant_id ?? ($user?->tenantId() ?? 1);
        return $this->walletService->getOrCreateTenantWallet($tenantId);
    }

    private function paidBookingStatus(FlightBooking $booking): BookingStatus
    {
        return $booking->manual_pnr_required && empty($booking->pnr)
            ? BookingStatus::PNR_PENDING
            : BookingStatus::CONFIRMED;
    }

    protected function assertWalletOwnerIsActive(FlightB2BWallet $wallet): void
    {
        if (! $wallet->is_active) {
            throw new DomainException('Wallet is inactive.');
        }

        $ownerType = is_object($wallet->owner_type) ? $wallet->owner_type->value : (string) $wallet->owner_type;
        $upperType = strtoupper($ownerType);

        if ($upperType === 'DISTRIBUTOR') {
            $dist = FlightDistributor::find($wallet->owner_id);
            if ($dist && ! $dist->isActive()) {
                throw new DomainException('Distributor account is suspended.');
            }
        } elseif ($upperType === 'AGENT') {
            $agent = FlightAgent::find($wallet->owner_id);
            if ($agent && ! $agent->isOperational()) {
                throw new DomainException('Agent account is suspended.');
            }
        }
    }

    // ── Direct UPI (the agency's own UPI QR gateway) ───────────────────────

    private function directUpi(): FlightDirectUpiGateway
    {
        return app(FlightDirectUpiGateway::class);
    }

    /**
     * 'auto' lets the server pick the agency's online gateway: Direct UPI when it
     * is set up (unless Razorpay is marked preferred), otherwise Razorpay. A retry
     * keeps the gateway of the payment it is resuming.
     */
    private function resolveGateway(int $tenantId, array $params, ?string $existingGateway): string
    {
        $gateway = strtolower($params['gateway'] ?? 'razorpay');
        if ($gateway === 'auto') {
            $gateway = $existingGateway ? strtolower($existingGateway) : $this->preferredOnlineGateway($tenantId);
        }
        if (! in_array($gateway, ['razorpay', 'mock', 'direct_upi'], true)) {
            throw new DomainException('Unsupported flight payment gateway.');
        }
        if ($gateway === 'mock' && ! app()->environment('local', 'testing')) {
            throw new DomainException('Mock payments are disabled outside local and test environments.');
        }
        if ($gateway === 'razorpay' && ! app()->environment('local', 'testing')
            && ! \Modules\Payment\Models\TenantPaymentGatewayConfig::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('provider', 'razorpay')->where('enabled', true)->exists()) {
            throw new DomainException('Online payment is not enabled for this agency.');
        }
        if ($gateway === 'direct_upi' && ! $this->directUpi()->isAvailable($tenantId)) {
            throw new DomainException('UPI payment is not set up for this agency.');
        }

        return $gateway;
    }

    public function preferredOnlineGateway(int $tenantId): string
    {
        if (! $this->directUpi()->isAvailable($tenantId)) {
            return 'razorpay';
        }
        $preferred = \Modules\Payment\Models\TenantPaymentGatewayConfig::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('enabled', true)->where('preferred', true)->value('provider');

        return $preferred === 'razorpay' ? 'razorpay' : 'direct_upi';
    }

    /** UPI QR details for the checkout; empty for other gateways. */
    private function upiSession(FlightPayment $payment): array
    {
        if (strtolower((string) $payment->gateway) !== 'direct_upi') {
            return [];
        }
        $meta = (array) ($payment->gateway_response_metadata ?? []);

        return [
            'qr_data'        => $meta['qr_data'] ?? null,
            'intent_url'     => $meta['intent_url'] ?? null,
            'upi_expires_at' => $meta['expires_at'] ?? null,
        ];
    }

    private function upiMinutes(?\DateTimeInterface $holdExpiresAt): int
    {
        if (! $holdExpiresAt) {
            return 15;
        }

        return (int) max(1, min(15, floor(($holdExpiresAt->getTimestamp() - time()) / 60)));
    }

    /**
     * An open UPI payment whose QR has expired cannot be reused. Check it one last
     * time (the customer may have paid in the final seconds); if still unpaid, close
     * it so the caller creates a fresh QR. Returns true when it was closed.
     */
    private function retireExpiredUpi(\Illuminate\Support\Collection $payments): bool
    {
        $lead = $payments->first();
        $expiresAt = data_get($lead?->gateway_response_metadata, 'expires_at');
        if (! $lead || strtolower((string) $lead->gateway) !== 'direct_upi' || ! $expiresAt
            || Carbon::parse($expiresAt)->isFuture()) {
            return false;
        }
        if ($this->syncDirectUpiPayment($lead)->status === FlightPaymentStatus::COMPLETED) {
            return false;
        }
        foreach ($payments as $payment) {
            if ($payment->status->canTransitionTo(FlightPaymentStatus::FAILED)) {
                $payment->transitionTo(FlightPaymentStatus::FAILED, ['failure_reason' => 'UPI QR expired before payment.']);
            }
        }

        return true;
    }

    /**
     * Ask the UPI gateway whether this payment was made, and confirm the booking
     * (every flight of a trip) only when the gateway reports PAID for exactly this
     * order and amount. The browser and the webhook body are never trusted for this.
     */
    public function syncDirectUpiPayment(FlightPayment $payment): FlightPayment
    {
        if (strtolower((string) $payment->gateway) !== 'direct_upi'
            || in_array($payment->status, [FlightPaymentStatus::COMPLETED, FlightPaymentStatus::REFUNDED, FlightPaymentStatus::CANCELLED], true)) {
            return $payment;
        }
        $remote = $this->directUpi()->fetch($payment);
        if (! $remote || strtoupper((string) ($remote['status'] ?? '')) !== 'PAID') {
            return $payment;
        }

        $siblings = $this->orderSiblings($payment);
        $matches = hash_equals((string) $payment->gateway_order_id, (string) ($remote['id'] ?? ''))
            && $siblings->pluck('payment_reference')->contains((string) ($remote['merchant_order_id'] ?? ''))
            && abs((float) ($remote['amount'] ?? -1) - $this->orderTotal($siblings)) < 0.01
            && strtoupper((string) ($remote['currency'] ?? 'INR')) === strtoupper((string) ($payment->currency ?: 'INR'));
        if (! $matches) {
            \Illuminate\Support\Facades\Log::warning('Flight UPI payment does not match the gateway record', [
                'payment_reference' => $payment->payment_reference, 'gateway_payment_id' => $remote['id'] ?? null,
            ]);

            return $payment;
        }

        $closed = $siblings->contains(function (FlightPayment $sibling) {
            $status = FlightBooking::whereKey($sibling->flight_booking_id)->first()?->booking_status;

            return in_array($status, [BookingStatus::CANCELLED, BookingStatus::FAILED], true);
        });
        if ($closed) {
            // Money arrived after the booking was released: staff must refund or rebook.
            \Illuminate\Support\Facades\Log::warning('Flight UPI payment received for a closed booking', [
                'payment_reference' => $payment->payment_reference, 'gateway_payment_id' => $remote['id'] ?? null,
            ]);

            return $payment;
        }

        $this->completeCheckedPayment($payment, (string) ($remote['transaction_reference'] ?? $remote['id']));

        return $payment->refresh();
    }
}
