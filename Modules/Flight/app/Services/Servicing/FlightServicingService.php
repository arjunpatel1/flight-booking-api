<?php

namespace Modules\Flight\Services\Servicing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Flight\Enums\AmendmentStatus;
use Modules\Flight\Enums\AmendmentType;
use Modules\Flight\Enums\BookingStatus;
use Modules\Flight\Enums\FlightPaymentStatus;
use Modules\Flight\Enums\PaymentStatus;
use Modules\Flight\Enums\RefundMethod;
use Modules\Flight\Enums\RefundStatus;
use Modules\Flight\Enums\TicketStatus;
use Modules\Flight\Events\FlightBookingCancelled;
use Modules\Flight\Events\FlightBookingRescheduled;
use Modules\Flight\Models\FlightB2BWallet;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightBookingAmendment;
use Modules\Flight\Models\FlightFare;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Models\FlightPayment;
use Modules\Flight\Models\FlightRefund;
use Modules\Flight\Models\FlightTicket;
use Modules\Flight\Services\Payment\FlightPaymentGatewayAdapter;
use Modules\Flight\Services\Supplier\SupplierManager;
use Modules\Flight\Services\Wallet\FlightWalletLedgerService;
use Modules\Saas\Models\Tenant;

class FlightServicingService
{
    /** How long a reschedule payment link, and its held seat, stays open. */
    private const RESCHEDULE_LINK_MINUTES = 120;

    /** Razorpay refuses a link that expires sooner than this. */
    private const RESCHEDULE_LINK_MIN_MINUTES = 16;

    public function __construct(
        protected SupplierManager $supplierManager,
        protected FlightWalletLedgerService $walletLedgerService,
        protected FlightRefundPolicyService $refundPolicy,
        protected FlightPaymentGatewayAdapter $gatewayAdapter,
    ) {}

    // =========================================================================
    // 1. CANCELLATION & REFUND QUOTE
    // =========================================================================

    /**
     * Compute authoritative cancellation fee and refundable amount based on fare rules.
     */
    public function quoteCancellation(FlightBooking $booking): array
    {
        if ($booking->booking_status === BookingStatus::CANCELLED) {
            throw new DomainException("Booking {$booking->booking_reference} is already cancelled.");
        }

        $fare = $booking->flightFare;
        $fareRefundable = $fare === null || (bool) $fare->is_refundable;

        // An airline charge in the fare rules (per passenger) overrides the
        // tenant's time-based slabs; everything else follows the tenant policy.
        $fareRuleFee = $fare?->fareRule?->rule_details['cancellation_fee'] ?? null;
        $fareRuleFee = is_numeric($fareRuleFee) && (float) $fareRuleFee > 0 ? (float) $fareRuleFee : null;

        $tenant = $booking->tenant_id ? Tenant::query()->withoutGlobalScopes()->find($booking->tenant_id) : null;
        $policy = $this->refundPolicy->policyFor($tenant);

        return $this->refundPolicy->quote($booking, $policy, $fareRefundable, $fareRuleFee);
    }

    /** An open booking no money was taken for, so cancelling it refunds nothing. */
    public function isUnpaid(FlightBooking $booking): bool
    {
        return $booking->booking_status !== BookingStatus::CANCELLED
            && in_array($booking->payment_status, [PaymentStatus::PENDING, PaymentStatus::FAILED], true);
    }

    private function unpaidCancellationQuote(FlightBooking $booking): array
    {
        return [
            'booking_reference' => $booking->booking_reference,
            'is_refundable' => false,
            'original_amount' => (float) $booking->total_amount,
            'cancellation_fee' => 0.0,
            'refundable_amount' => 0.0,
            'currency' => $booking->currency ?: 'INR',
            'applied_rule' => 'Nothing has been paid for this booking, so there is no charge and no refund.',
            'breakdown' => [],
            'policy_text' => 'Nothing has been paid for this booking, so there is no charge and no refund.',
        ];
    }

    /** Everything paid comes back: no cancellation charge, service or convenience fee. */
    private function fullRefundQuote(FlightBooking $booking): array
    {
        $total = round((float) $booking->total_amount, 2);

        return [
            'cancellation_fee' => 0.0,
            'refundable_amount' => $total,
            'applied_rule' => 'Seat could not be confirmed: full refund.',
            'breakdown' => [
                ['label' => 'Amount paid', 'amount' => $total],
                ['label' => 'Refund amount', 'amount' => $total],
            ],
        ];
    }

    // =========================================================================
    // 2. EXECUTE CANCELLATION & REFUND
    // =========================================================================

    /**
     * Cancel booking, release inventory seats, invoke supplier cancellation, and issue refund.
     * IDEMPOTENT: Retrying on an already-cancelled booking safely replays the existing cancellation and refund without double-crediting.
     */
    public function cancelBooking(
        FlightBooking $booking,
        string $reason = 'Customer cancellation',
        string $actor = 'agent',
        // We could not secure the seat (sold out / PNR never confirmed): the
        // customer is not at fault, so refund everything they paid.
        bool $fullRefund = false
    ): array {
        // A waiting payment link must end first: paid moves the booking, and
        // anything else gives its held seat back.
        $this->settlePendingReschedule($booking);

        $result = DB::transaction(function () use ($booking, $reason, $actor, $fullRefund) {
            // 1. Lock booking
            $booking = FlightBooking::where('id', $booking->id)->lockForUpdate()->firstOrFail();

            // 1a. IDEMPOTENCY GUARD: If already cancelled, return existing refund record safely
            if ($booking->booking_status === BookingStatus::CANCELLED) {
                $existingRefund = $booking->refunds()->latest('id')->first();

                return [
                    'success'           => true,
                    'booking_reference' => $booking->booking_reference,
                    'status'            => 'CANCELLED',
                    'cancellation_fee'  => (float) $booking->cancellation_fee,
                    'refund_amount'     => (float) $booking->refunded_amount,
                    'refund_reference'  => $existingRefund?->refund_reference,
                    'refund_method'     => $existingRefund?->refund_method?->value ?? 'NONE',
                    'refund_status'     => $existingRefund?->status?->value ?? 'NONE',
                    'wallet_credited'   => $existingRefund?->refund_method === RefundMethod::WALLET,
                    'wallet_balance'    => null,
                    'cancelled_at'      => $booking->cancelled_at?->toIso8601String() ?? now()->toIso8601String(),
                    'idempotent_replay' => true,
                ];
            }

            // 2. Authoritative Quote
            $quote = $fullRefund ? $this->fullRefundQuote($booking) : $this->quoteCancellation($booking);
            $cancellationFee = $quote['cancellation_fee'];
            $refundableAmount = $quote['refundable_amount'];

            // 3. Supplier Cancellation (Resolve active driver or fallback to mock)
            $supplierRef = $booking->supplier_booking_reference ?: $booking->booking_reference;
            $supplierRefundRef = null;

            try {
                $supplier = ! empty($booking->supplier_code) && $booking->supplier_code !== 'DIRECT'
                    ? $this->supplierManager->driver($booking->supplier_code)
                    : $this->supplierManager->default();

                $supplierResp = $supplier->cancel($supplierRef, [
                    'reason' => $reason,
                    'pnr'    => $booking->pnr,
                ]);
                $supplierRefundRef = $supplierResp->supplierPnr ?? $supplierResp->bookingReference ?? null;
            } catch (\Throwable $e) {
                // Log and proceed safely with local cancellation
            }

            // 4. Release Inventory
            $paxCount = max(1, $booking->passengers()->count() ?: ($booking->pax_count_adult + $booking->pax_count_child));
            if ($booking->flight_inventory_id) {
                $inv = FlightInventory::where('id', $booking->flight_inventory_id)->lockForUpdate()->first();
                if ($inv) {
                    $inv->increment('available_seats', $paxCount);
                }
            }

            // 5. Ticket State Transition: ISSUED -> CANCELLED
            $booking->tickets()->update([
                'status' => TicketStatus::CANCELLED,
            ]);

            // 6. Update Booking Status
            $booking->booking_status      = BookingStatus::CANCELLED;
            $booking->cancelled_at        = now();
            $booking->cancellation_reason = $reason;
            $booking->cancellation_fee    = $cancellationFee;
            $booking->refunded_amount     = $refundableAmount;
            $booking->ticket_status       = TicketStatus::CANCELLED;
            // payment_status becomes REFUNDED only once money is actually
            // returned (markRefundSettled), not when a refund is merely requested.
            $booking->save();

            // 7. Process Refund & Ledger Entry (Strict Idempotency Guard)
            $refund = null;
            $refundTxn = null;

            if ($refundableAmount > 0) {
                // Check if a refund has already been recorded for this cancellation
                $existingRefund = FlightRefund::where('flight_booking_id', $booking->id)
                    ->where('refund_type', 'CANCELLATION')
                    ->whereIn('status', [RefundStatus::PROCESSED, RefundStatus::PENDING])
                    ->first();

                if ($existingRefund) {
                    $refund = $existingRefund;
                } else {
                    // After a dearer reschedule there are two payments: refund the
                    // larger one, which is the original fare.
                    $payment = FlightPayment::where('flight_booking_id', $booking->id)
                        ->where('status', FlightPaymentStatus::COMPLETED)
                        ->orderByDesc('amount')
                        ->first();

                    $refundMethod = RefundMethod::ORIGINAL_PAYMENT;
                    $gatewayRefundId = null;
                    $walletTxnId = null;

                    // Check if payment was from B2B Agency Wallet
                    $wallet = FlightB2BWallet::where('tenant_id', $booking->tenant_id)->first();
                    $paymentMethod = is_object($payment?->payment_method) ? $payment->payment_method->value : (string) ($payment?->payment_method ?? '');
                    $isWalletPayment = (strtoupper($paymentMethod) === 'WALLET');

                    if ($isWalletPayment && $wallet !== null) {
                        $refundMethod = RefundMethod::WALLET;
                        $refundRef = 'REF-WLT-' . strtoupper(Str::random(8));

                        // Execute double-entry ledger refund
                        $refundTxn = $this->walletLedgerService->refundCredit(
                            wallet:           $wallet,
                            amount:           $refundableAmount,
                            refundReference:  $refundRef,
                            actor:            $actor,
                            bookingReference: $booking->booking_reference,
                            metadata: [
                                'booking_reference' => $booking->booking_reference,
                                'cancellation_fee'  => $cancellationFee,
                                'reason'            => $reason,
                            ]
                        );

                        $walletTxnId = $refundTxn->id;
                    } else {
                        // Gateway refund: recorded as PENDING here and sent to
                        // Razorpay only after this transaction commits (see
                        // processGatewayRefund), so a rollback can never leave
                        // money refunded for a booking that is not cancelled.
                        $refundMethod = RefundMethod::GATEWAY;
                    }
                    $isWalletRefund = $refundMethod === RefundMethod::WALLET;

                    // Create FlightRefund Record
                    $refund = FlightRefund::create([
                        'tenant_id'                 => $booking->tenant_id,
                        'flight_booking_id'         => $booking->id,
                        'flight_payment_id'         => $payment?->id,
                        'refund_reference'          => 'REF-' . strtoupper(Str::random(10)),
                        'supplier_refund_reference' => $supplierRefundRef,
                        'refund_type'               => 'CANCELLATION',
                        'refund_method'             => $refundMethod,
                        'original_amount'           => (float) $booking->total_amount,
                        'cancellation_penalty'      => $cancellationFee,
                        'refund_amount'             => $refundableAmount,
                        'currency'                  => $booking->currency ?: 'INR',
                        'status'                    => $isWalletRefund ? RefundStatus::PROCESSED : RefundStatus::PENDING,
                        'gateway_refund_id'         => $gatewayRefundId,
                        'wallet_transaction_id'     => $walletTxnId,
                        'reason'                    => $reason,
                        'processed_at'              => $isWalletRefund ? now() : null,
                        'audit_meta'                => [
                            'actor'             => $actor,
                            'supplier_code'     => $booking->supplier_code ?: 'MOCK_SUPPLIER',
                            'pax_count'         => $paxCount,
                            'booking_reference' => $booking->booking_reference,
                            'applied_rule'      => $quote['applied_rule'] ?? null,
                            'breakdown'         => $quote['breakdown'] ?? null,
                        ],
                    ]);

                    if ($isWalletRefund) {
                        $this->markRefundSettled($booking, $refund);
                    }
                }
            }

            // 8. Create Amendment Audit Record
            FlightBookingAmendment::create([
                'tenant_id'                    => $booking->tenant_id,
                'flight_booking_id'            => $booking->id,
                'amendment_reference'          => 'AMD-CAN-' . strtoupper(Str::random(8)),
                'amendment_type'               => AmendmentType::CANCELLATION,
                'previous_flight_inventory_id' => $booking->flight_inventory_id,
                'previous_fare'                => (float) $booking->total_amount,
                'reschedule_fee'               => $cancellationFee,
                'currency'                     => $booking->currency ?: 'INR',
                'status'                       => AmendmentStatus::COMPLETED,
                'previous_pnr'                 => $booking->pnr,
                'reason'                       => $reason,
                'completed_at'                 => now(),
                'audit_trail'                  => [
                    'actor'             => $actor,
                    'refundable_amount' => $refundableAmount,
                    'refund_reference'  => $refund?->refund_reference,
                ],
            ]);

            // 9. Dispatch Event (FlightRefundCompleted fires in markRefundSettled,
            //    once the money has actually been returned)
            if ($refund) {
                event(new FlightBookingCancelled($booking, $refund));
                event(new \Modules\Flight\Events\FlightRefundInitiated($refund, $booking));
            }

            return [
                'success'           => true,
                'booking_reference' => $booking->booking_reference,
                'status'            => 'CANCELLED',
                'cancellation_fee'  => $cancellationFee,
                'refund_amount'     => $refundableAmount,
                'refund_reference'  => $refund?->refund_reference,
                'refund_id'         => $refund?->id,
                'refund_method'     => $refund?->refund_method?->value ?? 'NONE',
                'refund_status'     => $refund?->status?->value ?? 'NONE',
                'wallet_credited'   => $refundTxn !== null,
                'wallet_balance'    => $refundTxn?->closing_balance,
                'cancelled_at'      => $booking->cancelled_at->toIso8601String(),
                'applied_rule'      => $quote['applied_rule'] ?? null,
                'breakdown'         => $quote['breakdown'] ?? [],
            ];
        });

        // 10. Send a pending gateway refund to Razorpay now that the
        //     cancellation is committed.
        if (($result['refund_status'] ?? null) === RefundStatus::PENDING->value && ! empty($result['refund_id'])) {
            $refund = $this->processGatewayRefund(FlightRefund::findOrFail($result['refund_id']));
            $result['refund_status'] = $refund->status->value;
            $result['refund_message'] = $refund->failure_reason;
        }
        unset($result['refund_id']);

        return $result;
    }

    /**
     * Send a PENDING (or FAILED, on retry) gateway refund to Razorpay, or, if
     * Razorpay already accepted it, check whether it has settled yet.
     * Safe to call repeatedly: an accepted refund is never sent again, and
     * Razorpay rejects a duplicate receipt.
     */
    public function processGatewayRefund(FlightRefund $refund): FlightRefund
    {
        return DB::transaction(function () use ($refund) {
            $refund = FlightRefund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($refund->refund_method !== RefundMethod::GATEWAY
                || ! in_array($refund->status, [RefundStatus::PENDING, RefundStatus::FAILED], true)) {
                return $refund;
            }

            $booking = FlightBooking::findOrFail($refund->flight_booking_id);
            $payment = $refund->flight_payment_id ? FlightPayment::find($refund->flight_payment_id) : null;
            if (! $payment) {
                $refund->update(['status' => RefundStatus::FAILED, 'failure_reason' => 'No completed payment is linked to this booking, so it cannot be refunded online.']);

                return $refund;
            }

            // Already accepted by Razorpay: only refresh its status.
            $alreadyAccepted = $refund->status === RefundStatus::PENDING && filled($refund->gateway_refund_id);
            $result = $alreadyAccepted
                ? $this->gatewayAdapter->fetchRefund($payment, (string) $refund->gateway_refund_id)
                : $this->gatewayAdapter->refund($payment, (float) $refund->refund_amount, $refund->refund_reference);

            $this->applyGatewayRefundResult($booking, $refund, $payment, $result, $alreadyAccepted ? 'status_check' : 'refund_request');

            return $refund->refresh();
        });
    }

    /**
     * Apply a Razorpay refund webhook (refund.processed / refund.failed /
     * refund.created). The refund is matched by its Razorpay id, or by the
     * reference we sent as the receipt when it was created.
     */
    public function applyGatewayRefundEvent(string $event, array $entity): array
    {
        $gatewayRefundId = (string) ($entity['id'] ?? '');
        $ourReference = (string) (data_get($entity, 'notes.refund_reference') ?: ($entity['receipt'] ?? ''));

        return DB::transaction(function () use ($event, $entity, $gatewayRefundId, $ourReference) {
            $refund = FlightRefund::query()
                ->where(function ($match) use ($gatewayRefundId, $ourReference) {
                    $match->where('gateway_refund_id', $gatewayRefundId !== '' ? $gatewayRefundId : '__none__');
                    if ($ourReference !== '') {
                        $match->orWhere('refund_reference', $ourReference);
                    }
                })
                ->lockForUpdate()
                ->first();
            if (! $refund) {
                return ['status' => 'not_found', 'message' => 'No matching flight refund for webhook.'];
            }
            if ($refund->status === RefundStatus::PROCESSED) {
                return ['status' => 'already_processed', 'reference' => $refund->refund_reference];
            }

            $payment = $refund->flight_payment_id ? FlightPayment::find($refund->flight_payment_id) : null;
            // The webhook must be about this payment and this amount.
            if ($payment && isset($entity['payment_id']) && (string) $entity['payment_id'] !== (string) $payment->gateway_transaction_id) {
                throw new DomainException('Refund webhook payment does not match the flight refund.');
            }
            if (isset($entity['amount']) && (int) $entity['amount'] !== (int) round((float) $refund->refund_amount * 100)) {
                throw new DomainException('Refund webhook amount does not match the flight refund.');
            }

            $result = $this->gatewayAdapter->mapRefundEntity($event === 'refund.failed' ? array_merge($entity, ['status' => 'failed']) : $entity);
            if ($event === 'refund.processed') {
                $result['status'] = 'processed';
            }
            $booking = FlightBooking::findOrFail($refund->flight_booking_id);
            $this->applyGatewayRefundResult($booking, $refund, $payment, $result, 'webhook:' . $event);

            return ['status' => strtolower($refund->refresh()->status->value), 'reference' => $refund->refund_reference];
        });
    }

    /** Record one Razorpay outcome on a refund (request, status check or webhook). */
    protected function applyGatewayRefundResult(FlightBooking $booking, FlightRefund $refund, ?FlightPayment $payment, array $result, string $source): void
    {
        $meta = (array) ($refund->audit_meta ?? []);
        $meta['gateway_attempts'][] = ['at' => now()->toIso8601String(), 'source' => $source, 'status' => $result['status'], 'message' => $result['message']];
        $refund->audit_meta = $meta;

        match ($result['status']) {
            // processed: money returned. pending: accepted by Razorpay, still
            // settling, so it stays PENDING until confirmed.
            'processed', 'pending' => $refund->fill([
                'status' => RefundStatus::PENDING,
                'gateway_refund_id' => $result['gateway_refund_id'] ?: $refund->gateway_refund_id,
                'failure_reason' => $result['status'] === 'pending' ? 'Accepted by Razorpay; waiting for the bank to settle it.' : null,
            ]),
            // Online refunds switched off: stays PENDING for manual processing.
            'disabled' => $refund->fill(['failure_reason' => $result['message']]),
            default => $refund->fill(['status' => RefundStatus::FAILED, 'failure_reason' => $result['message']]),
        };
        $refund->save();

        if ($result['status'] === 'processed') {
            $this->markRefundSettled($booking, $refund, $payment);
        }
    }

    /**
     * Record a refund made outside the system (e.g. from the Razorpay
     * dashboard while online refunds are switched off).
     */
    public function markRefundProcessedManually(FlightRefund $refund, string $externalReference, string $actor): FlightRefund
    {
        return DB::transaction(function () use ($refund, $externalReference, $actor) {
            $refund = FlightRefund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($refund->status === RefundStatus::PROCESSED) {
                return $refund;
            }
            if (! in_array($refund->status, [RefundStatus::PENDING, RefundStatus::FAILED], true)) {
                throw new DomainException('Only a pending or failed refund can be marked as processed.');
            }

            $meta = (array) ($refund->audit_meta ?? []);
            $meta['manual_settlement'] = ['by' => $actor, 'at' => now()->toIso8601String(), 'reference' => $externalReference];
            $refund->fill(['gateway_refund_id' => $externalReference, 'failure_reason' => null, 'audit_meta' => $meta])->save();

            $booking = FlightBooking::findOrFail($refund->flight_booking_id);
            $payment = $refund->flight_payment_id ? FlightPayment::find($refund->flight_payment_id) : null;
            $this->markRefundSettled($booking, $refund, $payment);

            return $refund->refresh();
        });
    }

    /** Money is on its way back: mark refund, payment, booking and tickets refunded. */
    protected function markRefundSettled(FlightBooking $booking, FlightRefund $refund, ?FlightPayment $payment = null): void
    {
        $refund->fill(['status' => RefundStatus::PROCESSED, 'processed_at' => $refund->processed_at ?? now()])->save();

        // A cheaper reschedule returns part of the fare: the booking stays paid
        // and its tickets stay valid.
        if ($refund->refund_type === 'RESCHEDULE_DOWNGRADE') {
            $payment?->update(['refunded_amount' => (float) $payment->refunded_amount + (float) $refund->refund_amount]);
            event(new \Modules\Flight\Events\FlightRefundCompleted($refund, $booking));

            return;
        }

        $payment?->update([
            'refunded_amount' => (float) $refund->refund_amount,
            'status'          => FlightPaymentStatus::REFUNDED,
        ]);

        $booking->tickets()->update(['status' => TicketStatus::REFUNDED]);
        $booking->forceFill([
            'payment_status' => PaymentStatus::REFUNDED,
            'ticket_status'  => TicketStatus::REFUNDED,
        ])->save();

        event(new \Modules\Flight\Events\FlightRefundCompleted($refund, $booking));
    }

    // =========================================================================
    // 3. RESCHEDULE ELIGIBILITY & REPLACEMENT SEARCH
    // =========================================================================

    /**
     * Check if a booking is eligible for rescheduling under fare rules.
     */
    public function checkRescheduleEligibility(FlightBooking $booking): array
    {
        if ($booking->booking_status === BookingStatus::CANCELLED) {
            return [
                'eligible'       => false,
                'reschedule_fee' => 0.00,
                'currency'       => $booking->currency ?: 'INR',
                'reason'         => 'Cancelled bookings cannot be rescheduled.',
            ];
        }

        $blocked = match (true) {
            $booking->payment_status !== PaymentStatus::PAID => 'Only a paid booking can be rescheduled.',
            in_array($booking->booking_status, [BookingStatus::FAILED, BookingStatus::COMPLETED], true) => 'This booking can no longer be rescheduled.',
            $this->bookingHasDeparted($booking) => 'This flight has already departed, so it cannot be rescheduled.',
            $this->pendingReschedule($booking) !== null => 'A payment link for another reschedule is still waiting. Check or cancel it first.',
            default => null,
        };
        if ($blocked) {
            return [
                'eligible'       => false,
                'reschedule_fee' => 0.00,
                'currency'       => $booking->currency ?: 'INR',
                'reason'         => $blocked,
            ];
        }

        $fare = $booking->flightFare;
        if ($fare !== null && ! $fare->is_changeable) {
            return [
                'eligible'       => false,
                'reschedule_fee' => 0.00,
                'currency'       => $booking->currency ?: 'INR',
                'reason'         => 'Fare rules prohibit flight rescheduling (non-changeable fare).',
            ];
        }

        // Tenant's Cancellation & Refund Policy sets the fee; a fare rule can override it.
        $tenant = $booking->tenant_id ? Tenant::query()->withoutGlobalScopes()->find($booking->tenant_id) : null;
        $rescheduleFee = (float) $this->refundPolicy->policyFor($tenant)['reschedule_fee'];
        if ($fare && $fare->fareRule && ! empty($fare->fareRule->rule_details['reschedule_fee'])) {
            $rescheduleFee = (float) $fare->fareRule->rule_details['reschedule_fee'];
        }

        return [
            'eligible'       => true,
            'reschedule_fee' => $rescheduleFee,
            'currency'       => $booking->currency ?: 'INR',
            'policy_text'    => 'Rescheduling permitted subject to fare difference and standard change fee.',
        ];
    }

    /**
     * Search available alternative flights on the same route with calculated fare differences.
     */
    public function searchReplacementFlights(FlightBooking $booking, array $params = []): array
    {
        $eligibility = $this->checkRescheduleEligibility($booking);
        if (! $eligibility['eligible']) {
            throw new DomainException($eligibility['reason'] ?? 'Booking is not eligible for rescheduling.');
        }

        $currentInv = $booking->flightInventory;
        $currentAmount = (float) $booking->total_amount;
        $rescheduleFee = (float) $eligibility['reschedule_fee'];
        $paxCount = $this->seatCount($booking);
        $paidByWallet = $this->isPaidByWallet($booking);
        $currentDate = $this->bookingDeparture($booking)?->toDateString();

        $query = FlightInventory::query()
            ->with([
                'airline' => fn ($relation) => $relation->withoutGlobalScopes(),
                'aircraft' => fn ($relation) => $relation->withoutGlobalScopes(),
                'originAirport' => fn ($relation) => $relation->withoutGlobalScopes(),
                'destinationAirport' => fn ($relation) => $relation->withoutGlobalScopes(),
            ])
            ->where('status', 'active')
            ->where('available_seats', '>=', $paxCount);

        // Same route only, by airport code. Without a known route, offer nothing:
        // never every route.
        [$originCode, $destinationCode] = $this->bookingRouteCodes($booking);
        if (! $originCode || ! $destinationCode) {
            return [
                'booking_reference' => $booking->booking_reference,
                'reschedule_fee'    => $rescheduleFee,
                'currency'          => $booking->currency ?: 'INR',
                'options'           => [],
            ];
        }
        $query->whereHas('originAirport', fn ($airport) => $airport->withoutGlobalScopes()->where('iata_code', $originCode))
            ->whereHas('destinationAirport', fn ($airport) => $airport->withoutGlobalScopes()->where('iata_code', $destinationCode));
        // Every cabin is offered: staff filter by date, cabin or both.
        // A flight is a time of day plus the dates it operates on.
        $candidates = [];
        foreach ($query->limit(100)->get() as $inv) {
            foreach ($this->scheduledDates($inv) as $date) {
                $departure = $this->flightDeparture($inv, $date);
                if (! $departure || $departure->isPast() || $this->isBookedFlight($booking, $inv, $date)) {
                    continue;
                }
                $candidates[] = [$inv, $date, $departure];
            }
        }
        usort($candidates, fn ($a, $b) => $a[2] <=> $b[2]);

        $results = [];

        foreach (array_slice($candidates, 0, 60) as [$inv, $date, $departure]) {
            $change = $this->describeChange($booking, $inv, $date);
            $priced = $this->rescheduleQuote($booking, $inv, $change['date_changed'] ? $rescheduleFee : 0.0, null, $change['is_downgrade']);
            $newPrice = $priced['new_total'];
            $fareDiff = $priced['fare_difference'];
            $totalAdditional = $priced['additional_charge'];

            $depTime = $departure->toIso8601String();
            $arrTime = $this->flightArrival($inv, $departure)->toIso8601String();

            $results[] = [
                'inventory_id'            => $inv->id,
                'departure_date'          => $date,
                'change_type'             => $change['type'],
                'current_cabin'           => $change['current_cabin'],
                'is_downgrade'            => $change['is_downgrade'],
                'flight_number'           => $inv->flight_number,
                'airline_name'            => $inv->airline?->name ?? 'Airline',
                'airline_code'            => $inv->airline?->iata_code ?? 'AI',
                'aircraft_model'          => $inv->aircraft?->model ?? 'A320',
                'departure_airport'       => $inv->originAirport?->iata_code ?? 'DEL',
                'arrival_airport'         => $inv->destinationAirport?->iata_code ?? 'BOM',
                'departure_time'          => $depTime,
                'arrival_time'            => $arrTime,
                'cabin_class'             => $inv->cabin_class ?? 'ECONOMY',
                'available_seats'         => $inv->available_seats,
                'new_price'               => $newPrice,
                'previous_price'          => $currentAmount,
                'fare_difference'         => $fareDiff,
                'reschedule_fee'          => $change['date_changed'] ? $rescheduleFee : 0.0,
                'net_settlement'          => $priced['net_settlement'],
                'total_additional_charge' => $totalAdditional,
                'refund_due'              => $priced['refund_due'],
                'payment_link_required'   => $totalAdditional > 0 && ! $paidByWallet,
                'currency'                => $booking->currency ?: 'INR',
            ];
        }

        return [
            'booking_reference' => $booking->booking_reference,
            'reschedule_fee'    => $rescheduleFee,
            'currency'          => $booking->currency ?: 'INR',
            'options'           => $results,
        ];
    }

    // =========================================================================
    // 4. EXECUTE RESCHEDULE & AMENDMENT
    // =========================================================================

    /**
     * Move a paid booking to another flight on the same route.
     *  - Nothing extra owed: moves now, and any refund goes back through Razorpay.
     *  - Extra owed (online payment): holds the seat and sends a payment link. The
     *    booking moves only once the link is paid. See completePendingReschedule().
     *  - Extra owed (B2B wallet): debits the wallet and moves now.
     */
    public function rescheduleBooking(
        FlightBooking $booking,
        int $newInventoryId,
        ?int $newFareId = null,
        string $reason = 'Customer reschedule request',
        string $actor = 'agent',
        ?string $departureDate = null
    ): FlightBooking {
        $refundId = null;
        $pendingAmendmentId = null;

        $updated = DB::transaction(function () use ($booking, $newInventoryId, $newFareId, $reason, $actor, $departureDate, &$refundId, &$pendingAmendmentId) {
            $booking = FlightBooking::where('id', $booking->id)->lockForUpdate()->firstOrFail();

            $eligibility = $this->checkRescheduleEligibility($booking);
            if (! $eligibility['eligible']) {
                throw new DomainException($eligibility['reason'] ?? 'Booking is not eligible for rescheduling.');
            }

            $newInventory = FlightInventory::where('id', $newInventoryId)->lockForUpdate()->firstOrFail();
            $departureAt = $this->assertValidReplacement($booking, $newInventory, $departureDate);

            // Cabin change on the same flight: fare difference only. A downgrade
            // is never refunded.
            $change = $this->describeChange($booking, $newInventory, $departureAt->toDateString());
            $rescheduleFee = $change['date_changed'] ? (float) $eligibility['reschedule_fee'] : 0.0;
            $priced = $this->rescheduleQuote($booking, $newInventory, $rescheduleFee, $newFareId, $change['is_downgrade']);
            $priced['departure_at'] = $departureAt;
            $priced['change'] = $change;
            $paidByWallet = $this->isPaidByWallet($booking);
            $wallet = $paidByWallet ? FlightB2BWallet::where('tenant_id', $booking->tenant_id)->first() : null;

            if ($priced['additional_charge'] > 0 && ! $wallet) {
                $pendingAmendmentId = $this->holdSeatForPaymentLink($booking, $newInventory, $priced, $rescheduleFee, $reason, $actor)->id;

                return $booking;
            }

            $reschedulePaymentTxn = null;
            $refundRecord = null;

            if ($priced['additional_charge'] > 0) {
                if ((float) $wallet->purchasing_power < $priced['additional_charge']) {
                    throw new DomainException("Insufficient wallet balance for reschedule additional charge of {$booking->currency} {$priced['additional_charge']}. Available purchasing power: {$booking->currency} {$wallet->purchasing_power}.");
                }

                $reschedulePaymentTxn = $this->walletLedgerService->debit(
                    wallet:        $wallet,
                    amount:        $priced['additional_charge'],
                    reference:     'DEB-RES-' . strtoupper(Str::random(8)),
                    actor:         $actor,
                    contraAccount: 'SYSTEM:RESCHEDULE_CHARGE',
                    description:   "Reschedule additional charge for {$booking->booking_reference}",
                    metadata: [
                        'type'                    => 'RESCHEDULE_CHARGE',
                        'booking_reference'       => $booking->booking_reference,
                        'fare_difference'         => $priced['fare_difference'],
                        'reschedule_fee'          => $rescheduleFee,
                        'total_additional_charge' => $priced['additional_charge'],
                    ]
                );

                FlightPayment::create([
                    'tenant_id'                 => $booking->tenant_id,
                    'flight_booking_id'         => $booking->id,
                    'payment_reference'         => 'PAY-RES-' . strtoupper(Str::random(10)),
                    'payment_method'            => 'WALLET',
                    'gateway'                   => 'wallet',
                    'amount'                    => $priced['additional_charge'],
                    'currency'                  => $booking->currency ?: 'INR',
                    'status'                    => FlightPaymentStatus::COMPLETED,
                    'completed_at'              => now(),
                    'gateway_response_metadata' => [
                        'type'            => 'RESCHEDULE_CHARGE',
                        'fare_difference' => $priced['fare_difference'],
                        'reschedule_fee'  => $rescheduleFee,
                    ],
                ]);
            } elseif ($priced['refund_due'] > 0) {
                $refundRecord = $this->createRescheduleRefund($booking, $priced, $rescheduleFee, $wallet, $actor);
                if ($refundRecord->status === RefundStatus::PENDING) {
                    $refundId = $refundRecord->id;
                }
            }

            $moved = $this->moveBookingToFlight($booking, $newInventory, $priced, seatAlreadyHeld: false);

            $amendment = FlightBookingAmendment::create([
                'tenant_id'                    => $booking->tenant_id,
                'flight_booking_id'            => $booking->id,
                'amendment_reference'          => 'AMD-RES-' . strtoupper(Str::random(8)),
                'amendment_type'               => AmendmentType::RESCHEDULE,
                'previous_flight_inventory_id' => $moved['previous_inventory_id'],
                'new_flight_inventory_id'      => $newInventory->id,
                'previous_itinerary'           => $moved['previous_itinerary'],
                'new_itinerary'                => $moved['new_itinerary'],
                'previous_fare'                => $priced['previous_total'],
                'new_fare'                     => $priced['new_total'],
                'fare_difference'              => $priced['fare_difference'],
                'reschedule_fee'               => $rescheduleFee,
                'total_additional_charge'      => $priced['additional_charge'],
                'currency'                     => $booking->currency ?: 'INR',
                'status'                       => AmendmentStatus::COMPLETED,
                'previous_pnr'                 => $moved['previous_pnr'],
                'new_pnr'                      => $booking->pnr,
                'reason'                       => $reason,
                'completed_at'                 => now(),
                'audit_trail'                  => [
                    'actor'                   => $actor,
                    'net_settlement'          => $priced['net_settlement'],
                    'total_additional_charge' => $priced['additional_charge'],
                    'refund_due'              => $priced['refund_due'],
                    'refund_reference'        => $refundRecord?->refund_reference,
                    'payment_txn_id'          => $reschedulePaymentTxn?->id,
                    'pax_count'               => $this->seatCount($booking),
                    'change_type'             => $priced['change']['type'],
                    'previous_cabin'          => $priced['change']['current_cabin'],
                    'new_cabin'               => strtoupper((string) $newInventory->cabin_class),
                    'forfeited_on_downgrade'  => $priced['forfeited'],
                ],
            ]);

            event(new FlightBookingRescheduled($booking, $amendment));

            return $booking;
        });

        if ($pendingAmendmentId) {
            $this->sendRescheduleLink(FlightBookingAmendment::findOrFail($pendingAmendmentId));
        } else {
            if ($refundId) {
                $this->processGatewayRefund(FlightRefund::findOrFail($refundId));
            }
            $this->issueTicketAfterMove($updated);
        }

        return $updated->refresh()->load(['flightInventory', 'refunds', 'amendments', 'tickets']);
    }

    /** The reschedule waiting for its payment link to be paid, if any. */
    public function pendingReschedule(FlightBooking $booking): ?FlightBookingAmendment
    {
        return FlightBookingAmendment::where('flight_booking_id', $booking->id)
            ->where('amendment_type', AmendmentType::RESCHEDULE)
            ->where('status', AmendmentStatus::QUOTED)
            ->latest('id')
            ->first();
    }

    /** Ask Razorpay about the waiting payment link. Returns paid, released, pending or none. */
    public function checkPendingReschedule(FlightBooking $booking): string
    {
        $pending = $this->pendingReschedule($booking);
        if (! $pending) {
            return 'none';
        }

        $linkId = $pending->audit_trail['payment_link_id'] ?? null;
        if (! $linkId) {
            $this->releasePendingReschedule($pending, 'Payment link was never created.');

            return 'released';
        }

        $link = $this->gatewayAdapter->fetchPaymentLink($linkId);
        if ($link['status'] === 'paid') {
            $this->completePendingReschedule($pending, $link['payment_id'], $link['amount_paid']);

            return 'paid';
        }
        if (in_array($link['status'], ['expired', 'cancelled'], true)) {
            $this->releasePendingReschedule($pending, "Payment link {$link['status']}.");

            return 'released';
        }

        return 'pending';
    }

    /** End the waiting payment link now: paid moves the booking, otherwise the seat is released. */
    public function settlePendingReschedule(FlightBooking $booking): string
    {
        $outcome = $this->checkPendingReschedule($booking);
        if ($outcome !== 'pending') {
            return $outcome;
        }

        $pending = $this->pendingReschedule($booking);
        $this->gatewayAdapter->cancelPaymentLink($pending->audit_trail['payment_link_id']);
        $this->releasePendingReschedule($pending, 'Cancelled by staff or by cancelling the booking.');

        return 'released';
    }

    /** Scheduled: end payment links past their expiry time. */
    public function expirePendingReschedules(): int
    {
        $ended = 0;
        $pending = FlightBookingAmendment::query()->withoutGlobalScopes()
            ->where('amendment_type', AmendmentType::RESCHEDULE)
            ->where('status', AmendmentStatus::QUOTED)
            ->get();

        foreach ($pending as $amendment) {
            $expiresAt = $amendment->audit_trail['expires_at'] ?? null;
            if ($expiresAt && now()->lt(\Carbon\Carbon::parse($expiresAt))) {
                continue;
            }
            try {
                $booking = FlightBooking::query()->withoutGlobalScopes()->findOrFail($amendment->flight_booking_id);
                if ($this->settlePendingReschedule($booking) !== 'none') {
                    $ended++;
                }
            } catch (\Throwable $error) {
                // Razorpay unreachable: keep the seat and try again next run, so
                // a customer who just paid is never left without one.
                \Illuminate\Support\Facades\Log::warning('Reschedule payment link could not be settled.', ['amendment' => $amendment->id, 'error' => $error->getMessage()]);
            }
        }

        return $ended;
    }

    /** A signed Razorpay webhook about a payment link. */
    public function applyPaymentLinkEvent(string $event, array $payload): array
    {
        $link = (array) data_get($payload, 'payload.payment_link.entity', []);
        $linkId = (string) ($link['id'] ?? '');
        $payment = $linkId !== '' ? FlightPayment::where('gateway_order_id', $linkId)->first() : null;
        $amendment = $payment
            ? FlightBookingAmendment::where('flight_booking_id', $payment->flight_booking_id)
                ->where('amendment_type', AmendmentType::RESCHEDULE)
                ->where('status', AmendmentStatus::QUOTED)
                ->latest('id')
                ->first()
            : null;
        if (! $amendment || ($amendment->audit_trail['flight_payment_id'] ?? null) !== $payment->id) {
            return ['status' => 'ignored', 'message' => 'No waiting reschedule for this payment link.'];
        }

        if ($event === 'payment_link.paid') {
            $this->completePendingReschedule(
                $amendment,
                data_get($payload, 'payload.payment.entity.id'),
                (int) ($link['amount_paid'] ?? 0)
            );

            return ['status' => 'completed', 'reference' => $amendment->amendment_reference];
        }
        if (in_array($event, ['payment_link.expired', 'payment_link.cancelled'], true)) {
            $this->releasePendingReschedule($amendment, "Payment link event: {$event}.");

            return ['status' => 'released', 'reference' => $amendment->amendment_reference];
        }

        return ['status' => 'ignored', 'event' => $event];
    }

    /** The customer paid the link: record it and move the booking. Safe to call twice. */
    public function completePendingReschedule(FlightBookingAmendment $amendment, ?string $gatewayPaymentId, int $amountPaidPaise): void
    {
        $booking = DB::transaction(function () use ($amendment, $gatewayPaymentId, $amountPaidPaise) {
            $amendment = FlightBookingAmendment::whereKey($amendment->id)->lockForUpdate()->firstOrFail();
            if ($amendment->status !== AmendmentStatus::QUOTED) {
                return null;
            }

            $payment = FlightPayment::whereKey($amendment->audit_trail['flight_payment_id'] ?? 0)->lockForUpdate()->firstOrFail();
            if ($amountPaidPaise !== (int) round((float) $payment->amount * 100)) {
                throw new DomainException('The amount paid on the link does not match the reschedule charge.');
            }

            $booking = FlightBooking::whereKey($amendment->flight_booking_id)->lockForUpdate()->firstOrFail();
            $newInventory = FlightInventory::whereKey($amendment->new_flight_inventory_id)->lockForUpdate()->firstOrFail();

            $payment->transitionTo(FlightPaymentStatus::COMPLETED, [
                'gateway_transaction_id'   => $gatewayPaymentId,
                'reconciliation_reference' => 'RES-LINK-' . strtoupper(Str::random(8)),
            ]);

            $moved = $this->moveBookingToFlight($booking, $newInventory, [
                'new_total'    => (float) $amendment->new_fare,
                'fare'         => isset($amendment->audit_trail['new_fare_id']) ? FlightFare::find($amendment->audit_trail['new_fare_id']) : null,
                'departure_at' => \Carbon\Carbon::parse($amendment->audit_trail['new_departure_time']),
            ], seatAlreadyHeld: true);

            $amendment->update([
                'previous_itinerary' => $moved['previous_itinerary'],
                'new_itinerary'      => $moved['new_itinerary'],
                'previous_pnr'       => $moved['previous_pnr'],
                'new_pnr'            => $booking->pnr,
                'status'             => AmendmentStatus::COMPLETED,
                'completed_at'       => now(),
            ]);

            event(new FlightBookingRescheduled($booking, $amendment));

            return $booking;
        });

        if ($booking) {
            $this->issueTicketAfterMove($booking);
        }
    }

    /** Give the held seat back. The booking stays on its current flight. */
    private function releasePendingReschedule(FlightBookingAmendment $amendment, string $why): void
    {
        DB::transaction(function () use ($amendment, $why) {
            $amendment = FlightBookingAmendment::whereKey($amendment->id)->lockForUpdate()->firstOrFail();
            if ($amendment->status !== AmendmentStatus::QUOTED) {
                return;
            }

            FlightInventory::whereKey($amendment->new_flight_inventory_id)->lockForUpdate()->first()
                ?->increment('available_seats', (int) ($amendment->audit_trail['pax_count'] ?? 1));

            $payment = FlightPayment::whereKey($amendment->audit_trail['flight_payment_id'] ?? 0)->lockForUpdate()->first();
            if ($payment && $payment->status->canTransitionTo(FlightPaymentStatus::CANCELLED)) {
                $payment->transitionTo(FlightPaymentStatus::CANCELLED, ['failure_reason' => $why]);
            }

            $amendment->update([
                'status'      => AmendmentStatus::CANCELLED,
                'audit_trail' => array_merge((array) $amendment->audit_trail, ['released_reason' => $why, 'released_at' => now()->toIso8601String()]),
            ]);
        });
    }

    private function holdSeatForPaymentLink(FlightBooking $booking, FlightInventory $newInventory, array $priced, float $rescheduleFee, string $reason, string $actor): FlightBookingAmendment
    {
        $seats = $this->seatCount($booking);
        $newInventory->decrement('available_seats', $seats);

        $payment = FlightPayment::create([
            'tenant_id'                 => $booking->tenant_id,
            'flight_booking_id'         => $booking->id,
            'payment_reference'         => 'PAY-RES-' . strtoupper(Str::random(10)),
            'payment_method'            => 'GATEWAY',
            'gateway'                   => 'razorpay',
            'amount'                    => $priced['additional_charge'],
            'currency'                  => $booking->currency ?: 'INR',
            'status'                    => FlightPaymentStatus::INITIATED,
            'initiated_at'              => now(),
            'gateway_response_metadata' => [
                'type'            => 'RESCHEDULE_CHARGE',
                'fare_difference' => $priced['fare_difference'],
                'reschedule_fee'  => $rescheduleFee,
            ],
        ]);

        return FlightBookingAmendment::create([
            'tenant_id'                    => $booking->tenant_id,
            'flight_booking_id'            => $booking->id,
            'amendment_reference'          => 'AMD-RES-' . strtoupper(Str::random(8)),
            'amendment_type'               => AmendmentType::RESCHEDULE,
            'previous_flight_inventory_id' => $booking->flight_inventory_id,
            'new_flight_inventory_id'      => $newInventory->id,
            'previous_fare'                => $priced['previous_total'],
            'new_fare'                     => $priced['new_total'],
            'fare_difference'              => $priced['fare_difference'],
            'reschedule_fee'               => $rescheduleFee,
            'total_additional_charge'      => $priced['additional_charge'],
            'currency'                     => $booking->currency ?: 'INR',
            'status'                       => AmendmentStatus::QUOTED,
            'previous_pnr'                 => $booking->pnr,
            'reason'                       => $reason,
            'audit_trail'                  => [
                'actor'              => $actor,
                'pax_count'          => $seats,
                'flight_payment_id'  => $payment->id,
                'new_fare_id'        => $priced['fare']?->id,
                'new_flight_number'  => $newInventory->flight_number,
                'new_departure_time' => $priced['departure_at']->toIso8601String(),
                'change_type'        => $priced['change']['type'],
                'previous_cabin'     => $priced['change']['current_cabin'],
                'new_cabin'          => strtoupper((string) $newInventory->cabin_class),
            ],
        ]);
    }

    /** After the seat is held. If the link cannot be created, the seat goes straight back. */
    private function sendRescheduleLink(FlightBookingAmendment $amendment): void
    {
        $booking = FlightBooking::findOrFail($amendment->flight_booking_id);
        $payment = FlightPayment::findOrFail($amendment->audit_trail['flight_payment_id']);
        $departure = \Carbon\Carbon::parse($amendment->audit_trail['new_departure_time']);
        $expiresAt = now()->addMinutes(self::RESCHEDULE_LINK_MINUTES);
        if ($expiresAt->gt($departure)) {
            $expiresAt = $departure->copy();
        }

        try {
            if ($expiresAt->lt(now()->addMinutes(self::RESCHEDULE_LINK_MIN_MINUTES))) {
                throw new DomainException('This flight departs too soon to pay by link.');
            }

            $link = $this->gatewayAdapter->createPaymentLink(
                $payment,
                ['name' => $booking->contact_name, 'email' => $booking->contact_email, 'contact' => $booking->contact_phone],
                "Change to flight {$amendment->audit_trail['new_flight_number']} for booking {$booking->booking_reference}",
                $expiresAt->getTimestamp()
            );
        } catch (\Throwable $error) {
            $this->releasePendingReschedule($amendment, 'Payment link could not be created.');

            throw $error instanceof DomainException
                ? $error
                : new DomainException('The payment link could not be created. The booking was not changed.');
        }

        $payment->update(['gateway_order_id' => $link['id']]);
        $amendment->update(['audit_trail' => array_merge((array) $amendment->audit_trail, [
            'payment_link_id'  => $link['id'],
            'payment_link_url' => $link['short_url'],
            'expires_at'       => $expiresAt->toIso8601String(),
        ])]);
    }

    private function createRescheduleRefund(FlightBooking $booking, array $priced, float $rescheduleFee, ?FlightB2BWallet $wallet, string $actor): FlightRefund
    {
        $walletTxnId = null;
        if ($wallet) {
            $walletTxnId = $this->walletLedgerService->refundCredit(
                wallet:           $wallet,
                amount:           $priced['refund_due'],
                refundReference:  'REF-RES-WLT-' . strtoupper(Str::random(8)),
                actor:            $actor,
                bookingReference: $booking->booking_reference,
                metadata: [
                    'type'              => 'RESCHEDULE_DOWNGRADE_REFUND',
                    'booking_reference' => $booking->booking_reference,
                    'fare_difference'   => $priced['fare_difference'],
                    'reschedule_fee'    => $rescheduleFee,
                    'refund_due'        => $priced['refund_due'],
                ]
            )->id;
        }

        $payment = FlightPayment::where('flight_booking_id', $booking->id)
            ->where('status', FlightPaymentStatus::COMPLETED)
            ->orderByDesc('amount')
            ->first();

        // Online refunds stay PENDING until Razorpay confirms the money went back.
        return FlightRefund::create([
            'tenant_id'             => $booking->tenant_id,
            'flight_booking_id'     => $booking->id,
            'flight_payment_id'     => $payment?->id,
            'refund_reference'      => 'REF-RES-' . strtoupper(Str::random(10)),
            'refund_type'           => 'RESCHEDULE_DOWNGRADE',
            'refund_method'         => $wallet ? RefundMethod::WALLET : RefundMethod::GATEWAY,
            'original_amount'       => $priced['previous_total'],
            'cancellation_penalty'  => $rescheduleFee,
            'refund_amount'         => $priced['refund_due'],
            'currency'              => $booking->currency ?: 'INR',
            'status'                => $wallet ? RefundStatus::PROCESSED : RefundStatus::PENDING,
            'wallet_transaction_id' => $walletTxnId,
            'reason'                => 'Reschedule to a cheaper flight',
            'processed_at'          => $wallet ? now() : null,
            'audit_meta'            => [
                'actor'           => $actor,
                'fare_difference' => $priced['fare_difference'],
                'reschedule_fee'  => $rescheduleFee,
                'new_fare'        => $priced['new_total'],
            ],
        ]);
    }

    /** Swap seats and rewrite the booking for the new flight. */
    private function moveBookingToFlight(FlightBooking $booking, FlightInventory $newInventory, array $priced, bool $seatAlreadyHeld): array
    {
        $seats = $this->seatCount($booking);
        $previousInventoryId = $booking->flight_inventory_id;
        $previousItinerary = $booking->itinerary;
        $previousPnr = $booking->pnr;

        if ($previousInventoryId && $previousInventoryId !== $newInventory->id) {
            FlightInventory::where('id', $previousInventoryId)->lockForUpdate()->first()?->increment('available_seats', $seats);
        }
        if (! $seatAlreadyHeld) {
            $newInventory->decrement('available_seats', $seats);
        }

        $supplierRef = $booking->supplier_booking_reference ?: $booking->booking_reference;
        $departureAt = $priced['departure_at'];
        $arrivalAt = $this->flightArrival($newInventory, $departureAt);

        try {
            $supplier = ! empty($booking->supplier_code) && $booking->supplier_code !== 'DIRECT'
                ? $this->supplierManager->driver($booking->supplier_code)
                : $this->supplierManager->default();

            $supplier->reissue($supplierRef, [
                'new_flight_number' => $newInventory->flight_number,
                'departure_time'    => $departureAt->toIso8601String(),
                'previous_pnr'      => $previousPnr,
            ]);
        } catch (\Throwable $e) {
            // Non-fatal for internal reissue
        }

        // A flight's own airports and airline may be shared rows, which the tenant
        // scope would hide.
        $origin = $newInventory->originAirport()->withoutGlobalScopes()->first();
        $destination = $newInventory->destinationAirport()->withoutGlobalScopes()->first();
        $airline = $newInventory->airline()->withoutGlobalScopes()->first();
        $aircraft = $newInventory->aircraft()->withoutGlobalScopes()->first();

        // Booking pages and the e-ticket read origin/destination/departure/arrival.
        $newItinerary = array_merge((array) $previousItinerary, array_filter([
            'airline'           => $airline?->name,
            'airline_name'      => $airline?->name,
            'airline_code'      => $airline?->iata_code,
            'flight_number'     => $newInventory->flight_number,
            'aircraft_model'    => $aircraft?->model,
            'origin'            => $origin?->iata_code,
            'destination'       => $destination?->iata_code,
            'departure_airport' => $origin?->iata_code,
            'arrival_airport'   => $destination?->iata_code,
            'departure'         => ['date' => $departureAt->toDateString(), 'time' => $departureAt->format('H:i:s')],
            'arrival'           => ['date' => $arrivalAt->toDateString(), 'time' => $arrivalAt->format('H:i:s')],
            'departure_date'    => $departureAt->toDateString(),
            'departure_time'    => $departureAt->toIso8601String(),
            'arrival_time'      => $arrivalAt->toIso8601String(),
            'cabin_class'       => $newInventory->cabin_class ?? 'ECONOMY',
        ]));
        unset($newItinerary['pnr'], $newItinerary['pnr_number']);

        $fare = $priced['fare'] ?? null;
        $booking->flight_inventory_id = $newInventory->id;
        $booking->flight_fare_id = $fare?->id;
        if ($fare) {
            $booking->fare_snapshot = array_merge((array) $booking->fare_snapshot, [
                'fare_basis_code' => $fare->fare_basis_code,
                'cabin_class'     => $fare->cabin_class,
                'is_refundable'   => (bool) $fare->is_refundable,
                'is_changeable'   => (bool) $fare->is_changeable,
            ]);
        }
        $booking->total_amount = $priced['new_total'];
        $booking->rescheduled_at = now();
        $booking->reschedule_count += 1;

        // The old flight's tickets and PNR do not apply to the new flight.
        $booking->tickets()->update(['status' => TicketStatus::EXCHANGED]);

        if (in_array(strtoupper((string) $booking->supplier_code), ['INTERNAL', 'DIRECT'], true)) {
            $routePnr = app(\Modules\Flight\Services\Booking\FlightBookingService::class)
                ->routePnrFor($newInventory, $booking->tenant_id, $newItinerary);

            $booking->pnr = $routePnr;
            $booking->supplier_pnr = $routePnr;
            $booking->manual_pnr_required = $routePnr === null;
            $booking->ticket_status = TicketStatus::PENDING;
            if ($routePnr) {
                $newItinerary['pnr'] = $routePnr;
                $newItinerary['pnr_number'] = $routePnr;
            } else {
                // Staff assign the new flight's PNR, which then issues the ticket.
                // A deliberate step back: the state machine has no such move.
                $booking->booking_status = BookingStatus::PNR_PENDING;
            }
        }

        $booking->itinerary = $newItinerary;
        $booking->save();

        return [
            'previous_inventory_id' => $previousInventoryId,
            'previous_itinerary'    => $previousItinerary,
            'new_itinerary'         => $newItinerary,
            'previous_pnr'          => $previousPnr,
        ];
    }

    /** A new ticket when the new flight's PNR is already known. Otherwise staff assign the PNR first. */
    private function issueTicketAfterMove(FlightBooking $booking): void
    {
        $booking = $booking->fresh();
        if (empty($booking->pnr) || ! in_array(strtoupper((string) $booking->supplier_code), ['INTERNAL', 'DIRECT'], true)) {
            return;
        }

        try {
            app(\Modules\Flight\Services\Ticketing\FlightTicketingService::class)->issueTicket($booking);
        } catch (\Throwable $error) {
            \Illuminate\Support\Facades\Log::warning('New ticket could not be issued after reschedule.', ['booking' => $booking->booking_reference, 'error' => $error->getMessage()]);
        }
    }

    /** Returns when the chosen flight departs on the chosen date. */
    private function assertValidReplacement(FlightBooking $booking, FlightInventory $newInventory, ?string $departureDate): \Carbon\Carbon
    {
        [$originCode, $destinationCode] = $this->bookingRouteCodes($booking);
        $newOrigin = $newInventory->originAirport()->withoutGlobalScopes()->value('iata_code');
        $newDestination = $newInventory->destinationAirport()->withoutGlobalScopes()->value('iata_code');
        $departure = $this->flightDeparture($newInventory, $departureDate);
        $date = $departure?->toDateString();

        $problem = match (true) {
            $departure === null => 'Choose the date of the new flight.',
            ! in_array($date, $this->scheduledDates($newInventory, 366), true) => 'This flight does not operate on that date.',
            $this->isBookedFlight($booking, $newInventory, $date) => 'The booking is already on this flight and cabin.',
            $newInventory->status !== 'active' => 'This flight is not available.',
            $departure->isPast() => 'This flight has already departed.',
            ! $originCode || ! $destinationCode => "This booking's route is unknown, so it cannot be rescheduled.",
            strtoupper((string) $newOrigin) !== $originCode || strtoupper((string) $newDestination) !== $destinationCode => 'A booking can only move to a flight on the same route.',
            $newInventory->available_seats < $this->seatCount($booking) => "Insufficient seats available on replacement flight: required {$this->seatCount($booking)}, available {$newInventory->available_seats}.",
            default => null,
        };

        if ($problem) {
            throw new DomainException($problem);
        }

        return $departure;
    }

    /**
     * Prices the whole booking on the new flight. Fares are per passenger, while
     * the convenience fee and any discount the customer already has carry over.
     */
    private function rescheduleQuote(FlightBooking $booking, FlightInventory $newInventory, float $rescheduleFee, ?int $fareId = null, bool $noRefund = false): array
    {
        if ($fareId) {
            $fare = $newInventory->fares()->whereKey($fareId)->first();
            if (! $fare) {
                throw new DomainException('This fare does not belong to the selected flight.');
            }
        } else {
            // The new flight's own cabin decides the fare.
            $cabin = $newInventory->cabin_class ?: ($booking->flightFare?->cabin_class ?? data_get($booking->itinerary, 'cabin_class'));
            $fare = $newInventory->fares()->where('status', 'active')->where('cabin_class', $cabin)->first()
                ?? $newInventory->fares()->where('status', 'active')->first();
        }

        $pricing = (array) ($booking->pricing_breakdown ?? []);
        $perPassenger = $fare ? (float) $fare->final_customer_price : (float) $newInventory->base_price;
        $kept = (float) ($pricing['convenience_fee'] ?? 0) - (float) ($pricing['discount'] ?? 0);
        $newTotal = round(max(0, $perPassenger * $this->seatCount($booking) + $kept), 2);
        $previousTotal = (float) $booking->total_amount;
        $fareDifference = round($newTotal - $previousTotal, 2);
        $net = round($fareDifference + $rescheduleFee, 2);

        $owedBack = $net < 0 ? abs($net) : 0.00;

        return [
            'fare'              => $fare,
            'previous_total'    => $previousTotal,
            'new_total'         => $newTotal,
            'fare_difference'   => $fareDifference,
            'net_settlement'    => $net,
            'additional_charge' => max(0.00, $net),
            // A downgrade keeps the difference: nothing goes back.
            'refund_due'        => $noRefund ? 0.00 : $owedBack,
            'forfeited'         => $noRefund ? $owedBack : 0.00,
        ];
    }

    private function seatCount(FlightBooking $booking): int
    {
        return max(1, $booking->passengers()->count() ?: ((int) $booking->pax_count_adult + (int) $booking->pax_count_child));
    }

    private function isPaidByWallet(FlightBooking $booking): bool
    {
        $payment = FlightPayment::where('flight_booking_id', $booking->id)
            ->where('status', FlightPaymentStatus::COMPLETED)
            ->latest('id')
            ->first();
        $method = is_object($payment?->payment_method) ? $payment->payment_method->value : (string) ($payment?->payment_method ?? '');

        return strtoupper($method) === 'WALLET';
    }

    /** When the booked flight departs: the booking's own date, with the flight's time. */
    private function bookingDeparture(FlightBooking $booking): ?\Carbon\Carbon
    {
        return $this->refundPolicy->departureAt($booking)
            ?? ($booking->flightInventory ? $this->flightDeparture($booking->flightInventory, null) : null);
    }

    /**
     * Origin and destination airport codes: from the booked flight, or from the
     * itinerary when that flight has since been deleted.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function bookingRouteCodes(FlightBooking $booking): array
    {
        $inventory = $booking->flightInventory;
        $origin = $inventory?->originAirport()->withoutGlobalScopes()->value('iata_code');
        $destination = $inventory?->destinationAirport()->withoutGlobalScopes()->value('iata_code');

        $itinerary = (array) ($booking->itinerary ?? []);
        $code = function ($point): ?string {
            $value = is_array($point) ? ($point['code'] ?? $point['iata_code'] ?? null) : $point;

            return is_string($value) && preg_match('/^[A-Za-z]{3}$/', trim($value)) ? strtoupper(trim($value)) : null;
        };
        $origin ??= $code($itinerary['origin'] ?? null) ?? $code($itinerary['departure_airport'] ?? null);
        $destination ??= $code($itinerary['destination'] ?? null) ?? $code($itinerary['arrival_airport'] ?? null);

        return [$origin ? strtoupper($origin) : null, $destination ? strtoupper($destination) : null];
    }

    /** The flight, date and cabin the booking is already on. */
    private function isBookedFlight(FlightBooking $booking, FlightInventory $inventory, ?string $date): bool
    {
        if ($booking->flight_inventory_id) {
            return $inventory->id === $booking->flight_inventory_id
                && $date !== null && $date === $this->bookingDeparture($booking)?->toDateString();
        }

        return $this->isBookedService($booking, $inventory, $date)
            && strtoupper((string) $inventory->cabin_class) === $this->bookingCabin($booking);
    }

    /**
     * The same flight on the same date, in any cabin. Matched by flight number and
     * time too, because the booked inventory row may have been deleted.
     */
    private function isBookedService(FlightBooking $booking, FlightInventory $inventory, ?string $date): bool
    {
        if ($date === null || $date !== $this->bookingDeparture($booking)?->toDateString()) {
            return false;
        }

        $flight = fn ($number) => strtoupper(preg_replace('/[\s-]+/', '', (string) $number));
        $booked = $booking->flightInventory;
        $bookedNumber = $booked?->flight_number ?? data_get($booking->itinerary, 'flight_number');
        $bookedTime = substr((string) ($booked?->departure_time
            ?? (data_get($booking->itinerary, 'departure.time') ?: data_get($booking->itinerary, 'departure_time') ?: '')), 0, 5);
        $bookedTime = preg_match('/^\d{4}-\d{2}-\d{2}/', $bookedTime) ? '' : $bookedTime;

        if ($booked && ($inventory->origin_airport_id !== $booked->origin_airport_id || $inventory->destination_airport_id !== $booked->destination_airport_id)) {
            return false;
        }

        $inventoryTime = (string) $inventory->departure_time;
        $inventoryTime = preg_match('/^\d{4}-\d{2}-\d{2}/', $inventoryTime) ? \Carbon\Carbon::parse($inventoryTime)->format('H:i') : substr($inventoryTime, 0, 5);
        if ($booked && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $booked->departure_time)) {
            $bookedTime = \Carbon\Carbon::parse($booked->departure_time)->format('H:i');
        }

        return $flight($inventory->flight_number) === $flight($bookedNumber)
            && ($bookedTime === '' || ! preg_match('/^\d{2}:\d{2}$/', $bookedTime) || $inventoryTime === $bookedTime);
    }

    /**
     * What a move changes: the date or flight, the cabin, or both. Only a date or
     * flight change pays the reschedule fee; a lower cabin is a downgrade.
     *
     * @return array{type: string, date_changed: bool, cabin_changed: bool, is_downgrade: bool, current_cabin: ?string}
     */
    private function describeChange(FlightBooking $booking, FlightInventory $inventory, ?string $date): array
    {
        $rank = fn (?string $cabin) => ['ECONOMY' => 1, 'PREMIUM_ECONOMY' => 2, 'BUSINESS' => 3, 'FIRST' => 4][strtoupper((string) $cabin)] ?? 1;
        $current = $this->bookingCabin($booking);
        $new = strtoupper((string) $inventory->cabin_class);
        $dateChanged = ! $this->isBookedService($booking, $inventory, $date);
        $cabinChanged = $current !== null && $new !== '' && $new !== $current;

        return [
            'type'          => match (true) {
                $dateChanged && $cabinChanged => 'date_and_cabin',
                $cabinChanged                 => 'cabin',
                default                       => 'date',
            },
            'date_changed'  => $dateChanged,
            'cabin_changed' => $cabinChanged,
            'is_downgrade'  => $cabinChanged && $rank($new) < $rank($current),
            'current_cabin' => $current,
        ];
    }

    private function bookingCabin(FlightBooking $booking): ?string
    {
        $cabin = $booking->flightInventory?->cabin_class
            ?? data_get($booking->itinerary, 'cabin_class')
            ?? $booking->flightFare?->cabin_class;

        return $cabin ? strtoupper((string) $cabin) : null;
    }

    private function bookingHasDeparted(FlightBooking $booking): bool
    {
        return (bool) $this->bookingDeparture($booking)?->isPast();
    }

    /**
     * Inventory stores a time of day; the date comes from its schedule. Older
     * rows that hold a full date and time keep their own date.
     */
    private function flightDeparture(FlightInventory $inventory, ?string $date): ?\Carbon\Carbon
    {
        $time = (string) $inventory->departure_time;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $time)) {
            return \Carbon\Carbon::parse($time);
        }
        if (! $date || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        return \Carbon\Carbon::parse($date . ' ' . (substr($time, 0, 8) ?: '00:00:00'));
    }

    /** Arrival on the departure day, or the next day for an overnight flight. */
    private function flightArrival(FlightInventory $inventory, \Carbon\Carbon $departure): \Carbon\Carbon
    {
        $time = (string) $inventory->arrival_time;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $time)) {
            return \Carbon\Carbon::parse($time);
        }
        $arrival = \Carbon\Carbon::parse($departure->toDateString() . ' ' . (substr($time, 0, 8) ?: '00:00:00'));

        return $arrival->lt($departure) ? $arrival->addDay() : $arrival;
    }

    /**
     * Future dates this flight operates on: its dated admin routes, or else its
     * operating days, the same rules customer search uses.
     *
     * @return list<string> Y-m-d
     */
    private function scheduledDates(FlightInventory $inventory, int $days = 60): array
    {
        $time = (string) $inventory->departure_time;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $time)) {
            return [\Carbon\Carbon::parse($time)->toDateString()];
        }

        $today = \Carbon\Carbon::today();
        $routeDates = \Modules\Flight\Models\FlightRoute::query()->withoutGlobalScopes()
            ->where('tenant_id', $inventory->tenant_id)
            ->where('flight_number', $inventory->flight_number)
            ->where('origin_airport_id', $inventory->origin_airport_id)
            ->where('destination_airport_id', $inventory->destination_airport_id)
            ->whereNotNull('flight_date')
            ->get(['flight_date', 'flight_time'])
            ->filter(fn ($route) => ! $route->flight_time || substr((string) $route->flight_time, 0, 5) === substr($time, 0, 5))
            ->map(fn ($route) => $route->flight_date->toDateString())
            ->filter(fn (string $date) => $date >= $today->toDateString())
            ->unique()
            ->values()
            ->all();
        if ($routeDates !== []) {
            return $routeDates;
        }

        $operatingDays = array_map(fn ($day) => strtolower(trim((string) $day)), (array) ($inventory->operating_days ?? []));
        if ($operatingDays === []) {
            return [];
        }

        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $today->copy()->addDays($i);
            if (array_intersect($operatingDays, [strtolower($day->format('l')), strtolower($day->format('D')), $day->format('N'), $day->format('w')])) {
                $dates[] = $day->toDateString();
            }
        }

        return $dates;
    }

    /**
     * Get comprehensive servicing manifest including cancellation quote, refunds, and amendment history.
     */
    public function getServicingSummary(FlightBooking $booking): array
    {
        $booking->loadMissing(['refunds', 'amendments', 'flightFare.fareRule', 'tickets']);

        $cancellationQuote = [];
        try {
            $cancellationQuote = $this->isUnpaid($booking)
                ? $this->unpaidCancellationQuote($booking)
                : $this->quoteCancellation($booking);
        } catch (\Throwable $e) {
            $cancellationQuote = [
                'is_refundable'     => false,
                'cancellation_fee'  => (float) $booking->cancellation_fee,
                'refundable_amount' => (float) $booking->refunded_amount,
                'policy_text'       => $e->getMessage(),
            ];
        }

        $rescheduleEligibility = $this->checkRescheduleEligibility($booking);

        $pending = $this->pendingReschedule($booking);

        return [
            'pending_reschedule'     => $pending ? [
                'amendment_reference' => $pending->amendment_reference,
                'amount'              => (float) $pending->total_additional_charge,
                'currency'            => $pending->currency,
                'payment_link_url'    => $pending->audit_trail['payment_link_url'] ?? null,
                'expires_at'          => $pending->audit_trail['expires_at'] ?? null,
                'flight_number'       => $pending->audit_trail['new_flight_number'] ?? null,
                'departure_time'      => $pending->audit_trail['new_departure_time'] ?? null,
            ] : null,
            'booking_reference'      => $booking->booking_reference,
            'pnr'                    => $booking->pnr,
            'booking_status'         => is_object($booking->booking_status) ? $booking->booking_status->value : (string) $booking->booking_status,
            'payment_status'         => is_object($booking->payment_status) ? $booking->payment_status->value : (string) $booking->payment_status,
            'total_amount'           => (float) $booking->total_amount,
            'currency'               => $booking->currency ?: 'INR',
            'cancelled_at'           => $booking->cancelled_at?->toIso8601String(),
            'cancellation_reason'    => $booking->cancellation_reason,
            'cancellation_fee'       => (float) $booking->cancellation_fee,
            'refunded_amount'        => (float) $booking->refunded_amount,
            'rescheduled_at'         => $booking->rescheduled_at?->toIso8601String(),
            'reschedule_count'       => $booking->reschedule_count,
            'cancellation_quote'     => $cancellationQuote,
            'reschedule_eligibility' => $rescheduleEligibility,
            'refunds'                => $booking->refunds,
            'amendments'             => $booking->amendments,
        ];
    }
}
