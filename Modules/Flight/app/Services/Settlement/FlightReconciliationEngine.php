<?php

namespace Modules\Flight\Services\Settlement;

use Modules\Flight\Enums\BookingStatus;
use Modules\Flight\Enums\FlightPaymentStatus;
use Modules\Flight\Enums\PaymentStatus;
use Modules\Flight\Enums\SettlementMismatchSeverity;
use Modules\Flight\Enums\SettlementMismatchType;
use Modules\Flight\Enums\TicketStatus;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightPayment;
use Modules\Flight\Models\FlightWalletLedgerEntry;
use Modules\Flight\Models\FlightWalletTransaction;

class FlightReconciliationEngine
{
    /**
     * Perform 4-way reconciliation across:
     *  1. Supplier
     *  2. Payment Gateway
     *  3. B2B Wallet
     *  4. Booking
     *
     * Returns reconciliation details and a list of detected mismatches.
     *
     * @param FlightBooking $booking
     * @param array $calculatedFinancials
     * @return array
     */
    public function reconcileBooking(FlightBooking $booking, array $calculatedFinancials): array
    {
        $mismatches = [];
        $totalAmount = (float) $booking->total_amount;

        // ─────────────────────────────────────────────────────────────────────
        // 1. PAYMENT GATEWAY RECONCILIATION
        // ─────────────────────────────────────────────────────────────────────
        $payments = FlightPayment::where('flight_booking_id', $booking->id)->get();
        $gatewayCaptured = 0.0;
        $gatewayRefunded = 0.0;
        $hasGatewayPayment = false;

        foreach ($payments as $payment) {
            $status = $payment->status instanceof FlightPaymentStatus
                ? $payment->status->value
                : (string) $payment->status;

            if (in_array(strtoupper($status), ['SUCCESS', 'COMPLETED'])) {
                $gatewayCaptured += (float) $payment->amount;
                $hasGatewayPayment = true;
            }
        }

        $refunds = $booking->refunds()->get();
        foreach ($refunds as $refund) {
            $method = is_object($refund->refund_method) ? $refund->refund_method->value : (string) $refund->refund_method;
            if ($method === 'GATEWAY') {
                $gatewayRefunded += (float) $refund->refund_amount;
            }
        }
        $netGateway = round($gatewayCaptured - $gatewayRefunded, 2);

        // ─────────────────────────────────────────────────────────────────────
        // 2. B2B WALLET & DOUBLE-ENTRY LEDGER RECONCILIATION
        // ─────────────────────────────────────────────────────────────────────
        $walletTransactions = FlightWalletTransaction::where(function ($q) use ($booking) {
            $q->where('metadata->booking_id', $booking->id)
              ->orWhere('metadata->booking_reference', $booking->booking_reference)
              ->orWhere('description', 'LIKE', "%{$booking->booking_reference}%");
        })->get();

        $walletDebited = 0.0;
        $walletCredited = 0.0;
        $ledgerBalanced = true;
        $ledgerDiscrepancy = 0.0;

        foreach ($walletTransactions as $wTx) {
            $type = is_object($wTx->type) ? $wTx->type->value : (string) $wTx->type;
            if (in_array(strtoupper($type), ['DEBIT', 'BOOKING_DEBIT', 'BOOKING_PAYMENT', 'HOLD_CAPTURE'])) {
                $walletDebited += (float) $wTx->amount;
            } elseif (in_array(strtoupper($type), ['CREDIT', 'REFUND', 'RESCHEDULE_CREDIT'])) {
                $walletCredited += (float) $wTx->amount;
            }

            // Verify double-entry ledger balance for this transaction
            $entries = FlightWalletLedgerEntry::where('flight_wallet_transaction_id', $wTx->id)->get();
            if ($entries->isNotEmpty()) {
                $dr = 0.0;
                $cr = 0.0;
                foreach ($entries as $entry) {
                    $rawType = $entry->entry_type ?? $entry->type;
                    $entryType = is_object($rawType) ? $rawType->value : (string) $rawType;
                    if (strtoupper($entryType) === 'DEBIT') {
                        $dr += (float) $entry->amount;
                    } else {
                        $cr += (float) $entry->amount;
                    }
                }
                if (abs($dr - $cr) > 0.01) {
                    $ledgerBalanced = false;
                    $ledgerDiscrepancy += abs($dr - $cr);
                }
            }
        }
        $netWallet = round($walletDebited - $walletCredited, 2);

        // ─────────────────────────────────────────────────────────────────────
        // 3. SUPPLIER RECONCILIATION
        // ─────────────────────────────────────────────────────────────────────
        $expectedSupplierPayable = (float) ($calculatedFinancials['supplier_payable'] ?? 0);
        $recordedSupplierCost = null;

        if (isset($booking->meta['supplier_invoiced_cost'])) {
            $recordedSupplierCost = (float) $booking->meta['supplier_invoiced_cost'];
        } elseif (isset($booking->pricing_breakdown['supplier_fare'])) {
            $recordedSupplierCost = (float) ($booking->pricing_breakdown['supplier_fare'] ?? 0)
                + (float) ($booking->pricing_breakdown['taxes'] ?? 0)
                + (float) ($booking->pricing_breakdown['supplier_fees'] ?? 0);
        }

        $supplierVariance = $recordedSupplierCost !== null
            ? round(abs($expectedSupplierPayable - $recordedSupplierCost), 2)
            : 0.0;

        // ─────────────────────────────────────────────────────────────────────
        // 4. BOOKING INTEGRITY RECONCILIATION
        // ─────────────────────────────────────────────────────────────────────
        $isCancelled = ($booking->booking_status === BookingStatus::CANCELLED) || ($booking->cancelled_at !== null);
        $refundedAmount = (float) ($booking->refunded_amount ?? 0);
        $cancellationFee = (float) ($booking->cancellation_fee ?? 0);

        // ─────────────────────────────────────────────────────────────────────
        // DETECT MISMATCHES
        // ─────────────────────────────────────────────────────────────────────

        // Check A: Gateway payment mismatch
        $isPaid = ($booking->payment_status === PaymentStatus::PAID)
            || (is_string($booking->payment_status) && strtoupper($booking->payment_status) === 'PAID');

        if ($isPaid && ! $isCancelled) {
            $totalPaidCaptured = $gatewayCaptured + $walletDebited;
            if ($totalPaidCaptured > 0 && abs($totalPaidCaptured - $totalAmount) > 0.05) {
                $mismatches[] = [
                    'mismatch_type'      => SettlementMismatchType::PAYMENT_GATEWAY_MISMATCH->value,
                    'source_entity'      => 'GATEWAY',
                    'expected_amount'    => $totalAmount,
                    'actual_amount'      => round($totalPaidCaptured, 2),
                    'discrepancy_amount' => round(abs($totalPaidCaptured - $totalAmount), 2),
                    'severity'           => SettlementMismatchSeverity::HIGH->value,
                    'details'            => [
                        'reason'           => 'Paid amount does not match booking total',
                        'gateway_captured' => $gatewayCaptured,
                        'wallet_debited'   => $walletDebited,
                        'booking_amount'   => $totalAmount,
                    ],
                ];
            } elseif ($totalPaidCaptured == 0 && $totalAmount > 0) {
                // Marked PAID but zero financial transactions found
                $mismatches[] = [
                    'mismatch_type'      => SettlementMismatchType::PAYMENT_GATEWAY_MISMATCH->value,
                    'source_entity'      => 'GATEWAY',
                    'expected_amount'    => $totalAmount,
                    'actual_amount'      => 0.00,
                    'discrepancy_amount' => $totalAmount,
                    'severity'           => SettlementMismatchSeverity::CRITICAL->value,
                    'details'            => [
                        'reason' => 'Booking marked PAID but no successful gateway or wallet payments recorded',
                    ],
                ];
            }
        }

        // Check B: Wallet double-entry ledger imbalance
        if (! $ledgerBalanced) {
            $mismatches[] = [
                'mismatch_type'      => SettlementMismatchType::WALLET_LEDGER_MISMATCH->value,
                'source_entity'      => 'WALLET',
                'expected_amount'    => 0.00,
                'actual_amount'      => round($ledgerDiscrepancy, 2),
                'discrepancy_amount' => round($ledgerDiscrepancy, 2),
                'severity'           => SettlementMismatchSeverity::CRITICAL->value,
                'details'            => [
                    'reason'              => 'Wallet double-entry ledger debit/credit entries are not balanced',
                    'ledger_discrepancy'  => $ledgerDiscrepancy,
                    'wallet_transactions' => count($walletTransactions),
                ],
            ];
        }

        // Check C: Supplier payable mismatch
        if ($recordedSupplierCost !== null && $supplierVariance > 0.05) {
            $mismatches[] = [
                'mismatch_type'      => SettlementMismatchType::SUPPLIER_PAYABLE_MISMATCH->value,
                'source_entity'      => 'SUPPLIER',
                'expected_amount'    => $expectedSupplierPayable,
                'actual_amount'      => $recordedSupplierCost,
                'discrepancy_amount' => $supplierVariance,
                'severity'           => SettlementMismatchSeverity::HIGH->value,
                'details'            => [
                    'reason'           => 'Supplier payable differs from recorded supplier invoice/cost',
                    'supplier_code'    => $booking->supplier_code,
                    'expected_payable' => $expectedSupplierPayable,
                    'recorded_cost'    => $recordedSupplierCost,
                ],
            ];
        }

        // Check D: Unresolved refund or cancellation imbalance
        if ($isCancelled) {
            $accountedCancellation = round($refundedAmount + $cancellationFee, 2);
            if (abs($accountedCancellation - $totalAmount) > 0.05) {
                $mismatches[] = [
                    'mismatch_type'      => SettlementMismatchType::UNRESOLVED_REFUND->value,
                    'source_entity'      => 'BOOKING',
                    'expected_amount'    => $totalAmount,
                    'actual_amount'      => $accountedCancellation,
                    'discrepancy_amount' => round(abs($accountedCancellation - $totalAmount), 2),
                    'severity'           => SettlementMismatchSeverity::MEDIUM->value,
                    'details'            => [
                        'reason'           => 'Refund amount + cancellation penalty does not balance original booking total',
                        'refunded_amount'  => $refundedAmount,
                        'cancellation_fee' => $cancellationFee,
                        'original_amount'  => $totalAmount,
                    ],
                ];
            }
        }

        // Check E: Ticket status inconsistency
        $ticketStatus = is_object($booking->ticket_status) ? $booking->ticket_status->value : (string) $booking->ticket_status;
        if (strtoupper($ticketStatus) === 'ISSUED' && empty($booking->pnr)) {
            $mismatches[] = [
                'mismatch_type'      => SettlementMismatchType::TICKET_STATUS_MISMATCH->value,
                'source_entity'      => 'BOOKING',
                'expected_amount'    => 0.00,
                'actual_amount'      => 0.00,
                'discrepancy_amount' => 0.00,
                'severity'           => SettlementMismatchSeverity::LOW->value,
                'details'            => [
                    'reason' => 'Booking marked ticket ISSUED but PNR is missing',
                ],
            ];
        }

        $isMatched = empty($mismatches);

        return [
            'is_matched'   => $isMatched,
            'mismatches'   => $mismatches,
            'reconciliation_details' => [
                'booking' => [
                    'booking_reference' => $booking->booking_reference,
                    'total_amount'      => $totalAmount,
                    'booking_status'    => is_object($booking->booking_status) ? $booking->booking_status->value : (string) $booking->booking_status,
                    'payment_status'    => is_object($booking->payment_status) ? $booking->payment_status->value : (string) $booking->payment_status,
                    'ticket_status'     => $ticketStatus,
                    'refunded_amount'   => $refundedAmount,
                    'cancellation_fee'  => $cancellationFee,
                ],
                'gateway' => [
                    'captured_amount' => $gatewayCaptured,
                    'refunded_amount' => $gatewayRefunded,
                    'net_amount'      => $netGateway,
                    'payments_count'  => count($payments),
                ],
                'wallet' => [
                    'debited_amount'     => $walletDebited,
                    'credited_amount'    => $walletCredited,
                    'net_amount'         => $netWallet,
                    'is_ledger_balanced' => $ledgerBalanced,
                    'ledger_discrepancy' => $ledgerDiscrepancy,
                    'transactions_count' => count($walletTransactions),
                ],
                'supplier' => [
                    'supplier_code'    => $booking->supplier_code,
                    'expected_payable' => $expectedSupplierPayable,
                    'recorded_cost'    => $recordedSupplierCost,
                    'variance'         => $supplierVariance,
                ],
            ],
        ];
    }
}
