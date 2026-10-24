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
use Modules\Flight\Services\Supplier\SupplierManager;
use Modules\Flight\Services\Wallet\FlightWalletLedgerService;

class FlightServicingService
{
    public function __construct(
        protected SupplierManager $supplierManager,
        protected FlightWalletLedgerService $walletLedgerService,
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
        $originalAmount = (float) $booking->total_amount;
        $currency = $booking->currency ?: 'INR';

        // Check refundability
        $isRefundable = true;
        if ($fare !== null && ! $fare->is_refundable) {
            $isRefundable = false;
        }

        if (! $isRefundable) {
            return [
                'booking_reference' => $booking->booking_reference,
                'is_refundable'     => false,
                'original_amount'   => $originalAmount,
                'cancellation_fee'  => $originalAmount,
                'refundable_amount' => 0.00,
                'currency'          => $currency,
                'policy_text'       => 'Non-refundable fare per airline fare rules.',
            ];
        }

        // Determine cancellation fee from fare rules or standard default
        $cancellationFee = 1000.00;
        if ($fare && $fare->fareRule && ! empty($fare->fareRule->rule_details['cancellation_fee'])) {
            $cancellationFee = (float) $fare->fareRule->rule_details['cancellation_fee'];
        } elseif ($originalAmount > 0) {
            // Standard minimum fee or 20% of fare, whichever is smaller
            $cancellationFee = min($originalAmount, max(500.00, round($originalAmount * 0.20, 2)));
        }

        $cancellationFee = min($originalAmount, $cancellationFee);
        $refundableAmount = round(max(0.00, $originalAmount - $cancellationFee), 2);

        return [
            'booking_reference' => $booking->booking_reference,
            'is_refundable'     => true,
            'original_amount'   => $originalAmount,
            'cancellation_fee'  => $cancellationFee,
            'refundable_amount' => $refundableAmount,
            'currency'          => $currency,
            'policy_text'       => 'Refundable subject to standard cancellation penalty.',
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
        string $actor = 'agent'
    ): array {
        return DB::transaction(function () use ($booking, $reason, $actor) {
            // 1. Lock booking
            $booking = FlightBooking::where('id', $booking->id)->lockForUpdate()->firstOrFail();

            // 1a. IDEMPOTENCY GUARD: If already cancelled, return existing refund record safely
            if ($booking->booking_status === BookingStatus::CANCELLED) {
                $existingRefund = $booking->refunds()->where('status', RefundStatus::PROCESSED)->latest()->first();

                return [
                    'success'           => true,
                    'booking_reference' => $booking->booking_reference,
                    'status'            => 'CANCELLED',
                    'cancellation_fee'  => (float) $booking->cancellation_fee,
                    'refund_amount'     => (float) $booking->refunded_amount,
                    'refund_reference'  => $existingRefund?->refund_reference,
                    'refund_method'     => $existingRefund?->refund_method?->value ?? 'NONE',
                    'refund_status'     => $existingRefund?->status?->value ?? 'PROCESSED',
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

            if ($refundableAmount > 0) {
                $booking->payment_status = PaymentStatus::REFUNDED;
            }
            $booking->save();

            // 7. Process Refund & Ledger Entry (Strict Idempotency Guard)
            $refund = null;
            $refundTxn = null;

            if ($refundableAmount > 0) {
                // Check if a refund has already been recorded for this cancellation
                $existingRefund = FlightRefund::where('flight_booking_id', $booking->id)
                    ->where('refund_type', 'CANCELLATION')
                    ->where('status', RefundStatus::PROCESSED)
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
                        // Gateway refund (Razorpay / Mock)
                        $refundMethod = RefundMethod::GATEWAY;
                        $gatewayRefundId = 'RF-GTW-' . strtoupper(Str::random(10));

                        if ($payment) {
                            $payment->update([
                                'refunded_amount' => $refundableAmount,
                                'status'          => FlightPaymentStatus::REFUNDED,
                            ]);
                        }
                    }

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
                        'status'                    => RefundStatus::PROCESSED,
                        'gateway_refund_id'         => $gatewayRefundId,
                        'wallet_transaction_id'     => $walletTxnId,
                        'reason'                    => $reason,
                        'processed_at'              => now(),
                        'audit_meta'                => [
                            'actor'             => $actor,
                            'supplier_code'     => $booking->supplier_code ?: 'MOCK_SUPPLIER',
                            'pax_count'         => $paxCount,
                            'booking_reference' => $booking->booking_reference,
                        ],
                    ]);

                    // Ticket Lifecycle Step 3: Once refund is finalized, transition to REFUNDED
                    $booking->tickets()->update([
                        'status' => TicketStatus::REFUNDED,
                    ]);
                    $booking->update([
                        'ticket_status' => TicketStatus::REFUNDED,
                    ]);
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

            // 9. Dispatch Event
            if ($refund) {
                event(new FlightBookingCancelled($booking, $refund));
                event(new \Modules\Flight\Events\FlightRefundInitiated($refund, $booking));
                event(new \Modules\Flight\Events\FlightRefundCompleted($refund, $booking));
            }

            return [
                'success'           => true,
                'booking_reference' => $booking->booking_reference,
                'status'            => 'CANCELLED',
                'cancellation_fee'  => $cancellationFee,
                'refund_amount'     => $refundableAmount,
                'refund_reference'  => $refund?->refund_reference,
                'refund_method'     => $refund?->refund_method?->value ?? 'NONE',
                'refund_status'     => $refund?->status?->value ?? 'PROCESSED',
                'wallet_credited'   => $refundTxn !== null,
                'wallet_balance'    => $refundTxn?->closing_balance,
                'cancelled_at'      => $booking->cancelled_at->toIso8601String(),
            ];
        });
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
