<?php

namespace Modules\Flight\Enums;

enum SettlementMismatchType: string
{
    case PAYMENT_GATEWAY_MISMATCH   = 'PAYMENT_GATEWAY_MISMATCH';
    case WALLET_LEDGER_MISMATCH     = 'WALLET_LEDGER_MISMATCH';
    case SUPPLIER_PAYABLE_MISMATCH  = 'SUPPLIER_PAYABLE_MISMATCH';
    case COMMISSION_MISMATCH        = 'COMMISSION_MISMATCH';
    case UNRESOLVED_REFUND          = 'UNRESOLVED_REFUND';
    case TICKET_STATUS_MISMATCH     = 'TICKET_STATUS_MISMATCH';
    case BOOKING_DISCREPANCY        = 'BOOKING_DISCREPANCY';

    public function label(): string
    {
        return match ($this) {
            self::PAYMENT_GATEWAY_MISMATCH  => 'Payment Gateway Mismatch',
            self::WALLET_LEDGER_MISMATCH    => 'Wallet Ledger Imbalance',
            self::SUPPLIER_PAYABLE_MISMATCH => 'Supplier Payable Variance',
            self::COMMISSION_MISMATCH       => 'Commission Imbalance',
            self::UNRESOLVED_REFUND         => 'Unresolved Refund',
            self::TICKET_STATUS_MISMATCH    => 'Ticket Status Inconsistency',
            self::BOOKING_DISCREPANCY       => 'Booking Data Discrepancy',
        };
    }
}
