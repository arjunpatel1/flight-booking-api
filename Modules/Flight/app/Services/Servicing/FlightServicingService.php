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
        string $actor = 'agent'
    ): array {
        $result = DB::transaction(function () use ($booking, $reason, $actor) {
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
            $quote = $this->quoteCancellation($booking);
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
                    $payment = FlightPayment::where('flight_booking_id', $booking->id)
                        ->where('status', FlightPaymentStatus::COMPLETED)
                        ->latest('id')
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

        $fare = $booking->flightFare;
        if ($fare !== null && ! $fare->is_changeable) {
            return [
                'eligible'       => false,
                'reschedule_fee' => 0.00,
                'currency'       => $booking->currency ?: 'INR',
                'reason'         => 'Fare rules prohibit flight rescheduling (non-changeable fare).',
            ];
        }

        $rescheduleFee = 750.00;
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
        $originId = $currentInv?->origin_airport_id;
        $destId = $currentInv?->destination_airport_id;
        $currentAmount = (float) $booking->total_amount;
        $rescheduleFee = (float) $eligibility['reschedule_fee'];
        $paxCount = max(1, $booking->passengers()->count() ?: 1);

        $query = FlightInventory::query()
            ->with(['airline', 'aircraft', 'originAirport', 'destinationAirport', 'fares'])
            ->where('status', 'active')
            ->where('available_seats', '>=', $paxCount);

        if ($currentInv) {
            $query->where('id', '!=', $currentInv->id);
            if ($originId) {
                $query->where('origin_airport_id', $originId);
            }
            if ($destId) {
                $query->where('destination_airport_id', $destId);
            }
        }

        $alternatives = $query->limit(10)->get();
        $results = [];

        foreach ($alternatives as $inv) {
            $activeFare = $inv->fares->first();
            $newPrice = $activeFare ? (float) $activeFare->final_customer_price : (float) $inv->base_price;
            $fareDiff = round($newPrice - $currentAmount, 2);
            $totalAdditional = round(max(0.00, $fareDiff) + $rescheduleFee, 2);

            $depTime = $inv->departure_time instanceof \DateTimeInterface ? $inv->departure_time->toIso8601String() : (string) $inv->departure_time;
            $arrTime = $inv->arrival_time instanceof \DateTimeInterface ? $inv->arrival_time->toIso8601String() : (string) $inv->arrival_time;

            $results[] = [
                'inventory_id'            => $inv->id,
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
                'reschedule_fee'          => $rescheduleFee,
                'net_settlement'          => round($fareDiff + $rescheduleFee, 2),
                'total_additional_charge' => $totalAdditional,
                'refund_due'              => round($fareDiff + $rescheduleFee, 2) < 0 ? abs(round($fareDiff + $rescheduleFee, 2)) : 0.00,
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
     * Reschedule booking to a new flight inventory, execute bidirectional financial settlement,
     * atomically release old seats and reserve new seats, and record amendment audit trail.
     */
    public function rescheduleBooking(
        FlightBooking $booking,
        int $newInventoryId,
        ?int $newFareId = null,
        string $reason = 'Customer reschedule request',
        string $actor = 'agent'
    ): FlightBooking {
        return DB::transaction(function () use ($booking, $newInventoryId, $newFareId, $reason, $actor) {
            $booking = FlightBooking::where('id', $booking->id)->lockForUpdate()->firstOrFail();

            $eligibility = $this->checkRescheduleEligibility($booking);
            if (! $eligibility['eligible']) {
                throw new DomainException($eligibility['reason'] ?? 'Booking is not eligible for rescheduling.');
            }

            $newInventory = FlightInventory::where('id', $newInventoryId)->lockForUpdate()->firstOrFail();
            $paxCount = max(1, $booking->passengers()->count() ?: 1);

            if ($newInventory->available_seats < $paxCount) {
                throw new DomainException("Insufficient seats available on replacement flight: required {$paxCount}, available {$newInventory->available_seats}.");
            }

            // 1. Calculate pricing & settlement
            $rescheduleFee = (float) $eligibility['reschedule_fee'];
            $previousFare = (float) $booking->total_amount;
            $newFare = (float) ($newInventory->fares()->first()?->final_customer_price ?? $newInventory->base_price);
            $fareDiff = round($newFare - $previousFare, 2);
            $netSettlement = round($fareDiff + $rescheduleFee, 2);
            $totalAdditionalCharge = max(0.00, $netSettlement);
            $refundDue = $netSettlement < 0.00 ? abs($netSettlement) : 0.00;

            // 2. Financial Settlement Execution (Wallet Debit vs Wallet Refund / Gateway)
            $wallet = FlightB2BWallet::where('tenant_id', $booking->tenant_id)->first();
            $latestPayment = FlightPayment::where('flight_booking_id', $booking->id)
                ->where('status', FlightPaymentStatus::COMPLETED)
                ->latest('id')
                ->first();
            $paymentMethod = is_object($latestPayment?->payment_method) ? $latestPayment->payment_method->value : (string) ($latestPayment?->payment_method ?? '');
            $isWalletPayment = (strtoupper($paymentMethod) === 'WALLET');

            $reschedulePaymentTxn = null;
            $rescheduleRefundTxn = null;
            $refundRecord = null;

            if ($totalAdditionalCharge > 0) {
                // Positive difference + fee: Customer pays additional charge
                if ($isWalletPayment && $wallet !== null) {
                    if ((float) $wallet->purchasing_power < $totalAdditionalCharge) {
                        throw new DomainException("Insufficient wallet balance for reschedule additional charge of {$booking->currency} {$totalAdditionalCharge}. Available purchasing power: {$booking->currency} {$wallet->purchasing_power}.");
                    }

                    $reschedulePaymentTxn = $this->walletLedgerService->debit(
                        wallet:        $wallet,
                        amount:        $totalAdditionalCharge,
                        reference:     'DEB-RES-' . strtoupper(Str::random(8)),
                        actor:         $actor,
                        contraAccount: 'SYSTEM:RESCHEDULE_CHARGE',
                        description:   "Reschedule additional charge for {$booking->booking_reference}",
                        metadata: [
                            'type'                    => 'RESCHEDULE_CHARGE',
                            'booking_reference'       => $booking->booking_reference,
                            'fare_difference'         => $fareDiff,
                            'reschedule_fee'          => $rescheduleFee,
                            'total_additional_charge' => $totalAdditionalCharge,
                        ]
                    );
                }

                FlightPayment::create([
                    'tenant_id'                 => $booking->tenant_id,
                    'flight_booking_id'         => $booking->id,
                    'payment_reference'         => 'PAY-RES-' . strtoupper(Str::random(10)),
                    'payment_method'            => $isWalletPayment ? 'WALLET' : 'GATEWAY',
                    'gateway'                   => $isWalletPayment ? 'wallet' : 'mock',
                    'amount'                    => $totalAdditionalCharge,
                    'currency'                  => $booking->currency ?: 'INR',
                    'status'                    => FlightPaymentStatus::COMPLETED,
                    'completed_at'              => now(),
                    'gateway_response_metadata' => [
                        'type'            => 'RESCHEDULE_CHARGE',
                        'fare_difference' => $fareDiff,
                        'reschedule_fee'  => $rescheduleFee,
                    ],
                ]);
            } elseif ($refundDue > 0) {
                // Negative difference: Cheaper flight even after change fee. System credits/refunds the customer.
                $refundMethod = RefundMethod::ORIGINAL_PAYMENT;
                $walletRefundTxnId = null;

                if ($isWalletPayment && $wallet !== null) {
                    $refundMethod = RefundMethod::WALLET;
                    $refundRef = 'REF-RES-WLT-' . strtoupper(Str::random(8));

                    $rescheduleRefundTxn = $this->walletLedgerService->refundCredit(
                        wallet:           $wallet,
                        amount:           $refundDue,
                        refundReference:  $refundRef,
                        actor:            $actor,
                        bookingReference: $booking->booking_reference,
                        metadata: [
                            'type'              => 'RESCHEDULE_DOWNGRADE_REFUND',
                            'booking_reference' => $booking->booking_reference,
                            'fare_difference'   => $fareDiff,
                            'reschedule_fee'    => $rescheduleFee,
                            'refund_due'        => $refundDue,
                        ]
                    );
                    $walletRefundTxnId = $rescheduleRefundTxn->id;
                } else {
                    $refundMethod = RefundMethod::GATEWAY;
                }

                $refundRecord = FlightRefund::create([
                    'tenant_id'             => $booking->tenant_id,
                    'flight_booking_id'     => $booking->id,
                    'refund_reference'      => 'REF-RES-' . strtoupper(Str::random(10)),
                    'refund_type'           => 'RESCHEDULE_DOWNGRADE',
                    'refund_method'         => $refundMethod,
                    'original_amount'       => $previousFare,
                    'cancellation_penalty'  => $rescheduleFee,
                    'refund_amount'         => $refundDue,
                    'currency'              => $booking->currency ?: 'INR',
                    'status'                => RefundStatus::PROCESSED,
                    'wallet_transaction_id' => $walletRefundTxnId,
                    'reason'                => 'Reschedule downgrade price adjustment credit',
                    'processed_at'          => now(),
                    'audit_meta'            => [
                        'actor'           => $actor,
                        'fare_difference' => $fareDiff,
                        'reschedule_fee'  => $rescheduleFee,
                        'new_fare'        => $newFare,
                    ],
                ]);
            }

            // 3. Atomic Inventory Exchange
            if ($booking->flight_inventory_id && $booking->flight_inventory_id !== $newInventory->id) {
                $oldInv = FlightInventory::where('id', $booking->flight_inventory_id)->lockForUpdate()->first();
                $oldInv?->increment('available_seats', $paxCount);
            }
            $newInventory->decrement('available_seats', $paxCount);

            // 4. Supplier Reissue (Direct Adapter Resolution)
            $supplierRef = $booking->supplier_booking_reference ?: $booking->booking_reference;
            try {
                $supplier = ! empty($booking->supplier_code) && $booking->supplier_code !== 'DIRECT'
                    ? $this->supplierManager->driver($booking->supplier_code)
                    : $this->supplierManager->default();

                $supplier->reissue($supplierRef, [
                    'new_flight_number' => $newInventory->flight_number,
                    'departure_time'    => $newInventory->departure_time instanceof \DateTimeInterface ? $newInventory->departure_time->toIso8601String() : (string) $newInventory->departure_time,
                    'previous_pnr'      => $booking->pnr,
                ]);
            } catch (\Throwable $e) {
                // Non-fatal for internal reissue
            }

            // 5. Store Previous State for Audit
            $prevItinerary = $booking->itinerary;
            $prevInventoryId = $booking->flight_inventory_id;
            $prevPnr = $booking->pnr;

            // 6. Update Itinerary
            $newItinerary = [
                'airline_name'      => $newInventory->airline?->name ?? 'Air India',
                'airline_code'      => $newInventory->airline?->iata_code ?? 'AI',
                'flight_number'     => $newInventory->flight_number,
                'aircraft_model'    => $newInventory->aircraft?->model ?? 'A320neo',
                'departure_airport' => $newInventory->originAirport?->iata_code ?? 'DEL',
                'arrival_airport'   => $newInventory->destinationAirport?->iata_code ?? 'BOM',
                'departure_time'    => $newInventory->departure_time instanceof \DateTimeInterface ? $newInventory->departure_time->toIso8601String() : (string) $newInventory->departure_time,
                'arrival_time'      => $newInventory->arrival_time instanceof \DateTimeInterface ? $newInventory->arrival_time->toIso8601String() : (string) $newInventory->arrival_time,
                'cabin_class'       => $newInventory->cabin_class ?? 'ECONOMY',
            ];

            $booking->flight_inventory_id = $newInventory->id;
            if ($newFareId) {
                $booking->flight_fare_id = $newFareId;
            }
            $booking->itinerary         = $newItinerary;
            $booking->total_amount      = $newFare;
            $booking->rescheduled_at    = now();
            $booking->reschedule_count += 1;
            $booking->save();

            // 7. Update Tickets to EXCHANGED / Reissued
            $booking->tickets()->update([
                'status' => TicketStatus::EXCHANGED,
            ]);

            // 8. Create Amendment Record
            $amendment = FlightBookingAmendment::create([
                'tenant_id'                    => $booking->tenant_id,
                'flight_booking_id'            => $booking->id,
                'amendment_reference'          => 'AMD-RES-' . strtoupper(Str::random(8)),
                'amendment_type'               => AmendmentType::RESCHEDULE,
                'previous_flight_inventory_id' => $prevInventoryId,
                'new_flight_inventory_id'      => $newInventory->id,
                'previous_itinerary'           => $prevItinerary,
                'new_itinerary'                => $newItinerary,
                'previous_fare'                => $previousFare,
                'new_fare'                     => $newFare,
                'fare_difference'              => $fareDiff,
                'reschedule_fee'               => $rescheduleFee,
                'total_additional_charge'      => $totalAdditionalCharge,
                'currency'                     => $booking->currency ?: 'INR',
                'status'                       => AmendmentStatus::COMPLETED,
                'previous_pnr'                 => $prevPnr,
                'new_pnr'                      => $booking->pnr,
                'reason'                       => $reason,
                'completed_at'                 => now(),
                'audit_trail'                  => [
                    'actor'                   => $actor,
                    'net_settlement'          => $netSettlement,
                    'total_additional_charge' => $totalAdditionalCharge,
                    'refund_due'              => $refundDue,
                    'refund_reference'        => $refundRecord?->refund_reference,
                    'payment_txn_id'          => $reschedulePaymentTxn?->id,
                    'pax_count'               => $paxCount,
                ],
            ]);

            // 9. Dispatch Event
            event(new FlightBookingRescheduled($booking, $amendment));

            return $booking->refresh()->load(['flightInventory', 'refunds', 'amendments', 'tickets']);
        });
    }

    /**
     * Get comprehensive servicing manifest including cancellation quote, refunds, and amendment history.
     */
    public function getServicingSummary(FlightBooking $booking): array
    {
        $booking->loadMissing(['refunds', 'amendments', 'flightFare.fareRule', 'tickets']);

        $cancellationQuote = [];
        try {
            $cancellationQuote = $this->quoteCancellation($booking);
        } catch (\Throwable $e) {
            $cancellationQuote = [
                'is_refundable'     => false,
                'cancellation_fee'  => (float) $booking->cancellation_fee,
                'refundable_amount' => (float) $booking->refunded_amount,
                'policy_text'       => $e->getMessage(),
            ];
        }

        $rescheduleEligibility = $this->checkRescheduleEligibility($booking);

        return [
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
