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

        // 1. Verify hold & quote validity
        $this->verifyBookingPayability($booking);

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
        $gateway = strtolower($params['gateway'] ?? 'razorpay');
        if (! in_array($gateway, ['razorpay', 'mock'], true)) {
            throw new DomainException('Unsupported flight payment gateway.');
        }
        if ($gateway === 'mock' && ! app()->environment('local', 'testing')) {
            throw new DomainException('Mock payments are disabled outside local and test environments.');
        }

        // 3. Idempotency Check
        $idempotencyKey = $params['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $existing = FlightPayment::where('flight_booking_id', $booking->id)
                ->where('idempotency_key', $idempotencyKey)
                ->whereNotIn('status', [FlightPaymentStatus::FAILED, FlightPaymentStatus::CANCELLED])
                ->first();

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
                    ],
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
            $orderData = $this->gatewayAdapter->createOrder($payment);
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
            ],
        ];
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

            $booking = FlightBooking::whereKey($payment->flight_booking_id)->lockForUpdate()->firstOrFail();

            // Validate gateway order ID matches
            $givenOrderId = $payload['razorpay_order_id'] ?? $payload['order_id'] ?? null;
            $isMockGateway = strtolower($payment->gateway ?: '') === 'mock';
            if ($isMockGateway && ! app()->environment('local', 'testing')) {
                throw new DomainException('Mock payment verification is disabled outside local and test environments.');
            }
            if (! $payment->gateway_order_id || ! $givenOrderId || ! hash_equals((string) $payment->gateway_order_id, (string) $givenOrderId)) {
                $payment->transitionTo(FlightPaymentStatus::FAILED, [
                    'failure_reason' => 'Gateway order ID mismatch.',
                ]);
                event(new \Modules\Flight\Events\FlightPaymentFailed($payment, $booking, 'Gateway order ID mismatch.'));
                throw new DomainException('Gateway order ID does not match payment session.');
            }

            // Validate Signature
            $isValid = $this->gatewayAdapter->verifySignature($payment, $payload);
            if (! $isValid) {
                $payment->transitionTo(FlightPaymentStatus::FAILED, [
                    'failure_reason' => 'Payment signature verification failed.',
                ]);
                event(new \Modules\Flight\Events\FlightPaymentFailed($payment, $booking, 'Payment signature verification failed.'));
                throw new DomainException('Payment signature verification failed.');
            }

            if (! $this->gatewayAdapter->verifyCapturedPayment($payment, $payload)) {
                throw new DomainException('Razorpay has not captured this payment yet. Please check the payment status before retrying.');
            }

            $gatewayPaymentId = $payload['razorpay_payment_id'] ?? $payload['payment_id'] ?? ('PAY-' . Str::random(12));

            // Mark Payment COMPLETED
            $payment->transitionTo(FlightPaymentStatus::COMPLETED, [
                'gateway_transaction_id'    => $gatewayPaymentId,
                'reconciliation_reference'  => 'REC-' . strtoupper(Str::random(10)),
                'gateway_response_metadata' => array_merge($payment->gateway_response_metadata ?? [], $payload),
            ]);

            // Atomically mark Flight Booking confirmed
            $booking->update([
                'payment_status'    => PaymentStatus::PAID,
                'booking_status'    => $this->paidBookingStatus($booking),
                'payment_reference' => $payment->payment_reference,
                'confirmed_at'      => now(),
            ]);
            app(\Modules\Flight\Services\Booking\FlightOfferService::class)->redeemForBooking($booking);

            if ($booking->hold_id || $booking->hold_reference) {
                $hold = FlightHold::where('id', $booking->hold_id)
                    ->orWhere('hold_id', $booking->hold_reference)
                    ->lockForUpdate()
                    ->first();
                if ($hold && $hold->status === HoldStatus::ACTIVE) {
                    $hold->status = HoldStatus::CONVERTED;
                    $hold->save();
                }
            }

            // Emit Payment Confirmed event (Phase 16 will handle ticketing)
            event(new FlightPaymentConfirmed($payment, $booking));

            return $payment->refresh();
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

            // Emit Domain Event (No ticketing in Phase 15)
            event(new FlightPaymentConfirmed($payment, $lockedBooking));

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
            $entity = data_get($payload, 'payload.payment.entity') ?? $payload;
            if (in_array($event, ['payment.captured', 'order.paid'], true)
                && (($entity['order_id'] ?? null) !== $payment->gateway_order_id
                    || (int) ($entity['amount'] ?? 0) !== (int) round((float) $payment->amount * 100)
                    || strtoupper((string) ($entity['currency'] ?? '')) !== strtoupper((string) $payment->currency))) {
                throw new DomainException('Payment webhook details do not match the flight payment.');
            }

            // Idempotent duplicate check
            if ($payment->status === FlightPaymentStatus::COMPLETED) {
                return ['status' => 'already_completed', 'reference' => $payment->payment_reference];
            }

            if (in_array($event, ['payment.captured', 'order.paid'], true)) {
                $payment->transitionTo(FlightPaymentStatus::COMPLETED, [
                    'gateway_transaction_id'    => $paymentId ?? $payment->gateway_transaction_id,
                    'reconciliation_reference'  => 'WEBHOOK-' . strtoupper(Str::random(8)),
                    'gateway_response_metadata' => array_merge($payment->gateway_response_metadata ?? [], $payload),
                ]);

                $booking = FlightBooking::whereKey($payment->flight_booking_id)->lockForUpdate()->first();
                if ($booking && $booking->payment_status !== PaymentStatus::PAID) {
                    $booking->update([
                        'payment_status'    => PaymentStatus::PAID,
                        'booking_status'    => $this->paidBookingStatus($booking),
                        'payment_reference' => $payment->payment_reference,
                        'confirmed_at'      => now(),
                    ]);
                    app(\Modules\Flight\Services\Booking\FlightOfferService::class)->redeemForBooking($booking);
                    if ($booking->hold_id || $booking->hold_reference) {
                        $hold = FlightHold::where('id', $booking->hold_id)
                            ->orWhere('hold_id', $booking->hold_reference)
                            ->lockForUpdate()
                            ->first();
                        if ($hold && $hold->status === HoldStatus::ACTIVE) {
                            $hold->status = HoldStatus::CONVERTED;
                            $hold->save();
                        }
                    }
                    event(new FlightPaymentConfirmed($payment, $booking));
                }

                return ['status' => 'completed', 'reference' => $payment->payment_reference];
            }

            if (in_array($event, ['payment.failed'], true)) {
                if ($payment->status !== FlightPaymentStatus::FAILED) {
                    $payment->transitionTo(FlightPaymentStatus::FAILED, [
                        'failure_reason' => $payload['error']['description'] ?? 'Webhook reported payment failed.',
                    ]);
                }
                $booking = FlightBooking::whereKey($payment->flight_booking_id)->lockForUpdate()->first();
                if ($booking && ($booking->hold_id || $booking->hold_reference)) {
                    $hold = FlightHold::where('id', $booking->hold_id)
                        ->orWhere('hold_id', $booking->hold_reference)
                        ->lockForUpdate()
                        ->first();
                    if ($hold && $hold->status === HoldStatus::ACTIVE) {
                        app(\Modules\Flight\Services\Hold\FlightHoldService::class)
                            ->releaseHold($hold->id, 'Payment failed');
                    }
                }
                return ['status' => 'marked_failed', 'reference' => $payment->payment_reference];
            }

            return ['status' => 'processed', 'event' => $event];
        });
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

        return $query->firstOrFail();
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

        $query = FlightBooking::where('booking_reference', $identifier)
            ->orWhere('id', is_numeric($identifier) ? (int) $identifier : 0);

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
}
