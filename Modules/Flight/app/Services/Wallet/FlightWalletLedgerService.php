<?php

namespace Modules\Flight\Services\Wallet;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Flight\Enums\LedgerEntryType;
use Modules\Flight\Enums\WalletOwnerType;
use Modules\Flight\Enums\WalletTransactionType;
use Modules\Flight\Exceptions\CreditLimitExceededException;
use Modules\Flight\Exceptions\DuplicateTransactionException;
use Modules\Flight\Exceptions\InsufficientBalanceException;
use Modules\Flight\Exceptions\WalletInactiveException;
use Modules\Flight\Models\FlightAgent;
use Modules\Flight\Models\FlightB2BWallet;
use Modules\Flight\Models\FlightDistributor;
use Modules\Flight\Models\FlightWalletLedgerEntry;
use Modules\Flight\Models\FlightWalletTransaction;
use Modules\Saas\Models\Tenant;

class FlightWalletLedgerService
{
    /**
     * Retrieve or initialize Tenant's primary flight wallet.
     */
    public function getOrCreateTenantWallet(Tenant|int $tenant, string $currency = 'INR'): FlightB2BWallet
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return FlightB2BWallet::firstOrCreate(
            [
                'tenant_id'  => $tenantId,
                'owner_type' => WalletOwnerType::TENANT->value,
                'owner_id'   => $tenantId,
            ],
            [
                'account_number' => 'WLT-TNT-' . $tenantId,
                'currency'       => $currency,
                'balance'        => 0.00,
                'credit_limit'   => 0.00,
                'credit_used'    => 0.00,
                'is_active'      => true,
            ]
        );
    }

    /**
     * Retrieve or initialize Distributor's flight wallet.
     */
    public function getOrCreateDistributorWallet(FlightDistributor|int $distributor): FlightB2BWallet
    {
        $dist = $distributor instanceof FlightDistributor ? $distributor : FlightDistributor::findOrFail($distributor);

        return FlightB2BWallet::firstOrCreate(
            [
                'tenant_id'  => $dist->tenant_id,
                'owner_type' => WalletOwnerType::DISTRIBUTOR->value,
                'owner_id'   => $dist->id,
            ],
            [
                'account_number' => 'WLT-DST-' . $dist->id,
                'currency'       => 'INR',
                'balance'        => 0.00,
                'credit_limit'   => (float) $dist->credit_limit,
                'credit_used'    => 0.00,
                'is_active'      => true,
            ]
        );
    }

    /**
     * Retrieve or initialize Agent's flight wallet.
     */
    public function getOrCreateAgentWallet(FlightAgent|int $agent): FlightB2BWallet
    {
        $agt = $agent instanceof FlightAgent ? $agent : FlightAgent::findOrFail($agent);

        return FlightB2BWallet::firstOrCreate(
            [
                'tenant_id'  => $agt->tenant_id,
                'owner_type' => WalletOwnerType::AGENT->value,
                'owner_id'   => $agt->id,
            ],
            [
                'account_number' => 'WLT-AGT-' . $agt->id,
                'currency'       => 'INR',
                'balance'        => 0.00,
                'credit_limit'   => (float) $agt->credit_limit,
                'credit_used'    => 0.00,
                'is_active'      => true,
            ]
        );
    }

    /**
     * Wallet Recharge: external bank or payment gateway funds deposited into wallet.
     * Double-Entry: DEBIT Contra (Payment Gateway) -> CREDIT Wallet.
     */
    public function recharge(
        FlightB2BWallet|int $wallet,
        float $amount,
        string $reference,
        string $actor,
        ?string $contraAccount = 'SYSTEM:PAYMENT_GATEWAY',
        ?string $description = null,
        array $metadata = []
    ): FlightWalletTransaction {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Recharge amount must be strictly greater than zero.');
        }

        $walletId = $wallet instanceof FlightB2BWallet ? $wallet->id : $wallet;

        return DB::transaction(function () use ($walletId, $amount, $reference, $actor, $contraAccount, $description, $metadata) {
            $lockedWallet = $this->lockWallet($walletId);
            $this->checkDuplicateReference($lockedWallet->tenant_id, $reference);

            $balances = $lockedWallet->applyAtomicCredit($amount);
            $txnId = $this->generateTransactionId();
            $contra = $contraAccount ?? 'SYSTEM:PAYMENT_GATEWAY';

            $transaction = FlightWalletTransaction::create([
                'tenant_id'              => $lockedWallet->tenant_id,
                'transaction_id'         => $txnId,
                'reference'              => $reference,
                'type'                   => WalletTransactionType::RECHARGE->value,
                'debit_account'          => $contra,
                'credit_account'         => $lockedWallet->account_identifier,
                'credit_wallet_id'       => $lockedWallet->id,
                'amount'                 => $amount,
                'currency'               => $lockedWallet->currency,
                'opening_balance'        => $balances['opening_balance'],
                'closing_balance'        => $balances['closing_balance'],
                'credit_opening_balance' => $balances['opening_balance'],
                'credit_closing_balance' => $balances['closing_balance'],
                'actor'                  => $actor,
                'description'            => $description ?? sprintf('Wallet recharge of %.2f %s', $amount, $lockedWallet->currency),
                'metadata'               => $metadata,
            ]);

            // Ledger Entry 1: Debit Gateway/Contra
            FlightWalletLedgerEntry::create([
                'tenant_id'                    => $lockedWallet->tenant_id,
                'flight_wallet_transaction_id' => $transaction->id,
                'account_identifier'           => $contra,
                'entry_type'                   => LedgerEntryType::DEBIT->value,
                'amount'                       => $amount,
                'opening_balance'              => 0.00,
                'closing_balance'              => $amount,
            ]);

            // Ledger Entry 2: Credit Wallet
            FlightWalletLedgerEntry::create([
                'tenant_id'                    => $lockedWallet->tenant_id,
                'flight_wallet_transaction_id' => $transaction->id,
                'wallet_id'                    => $lockedWallet->id,
                'account_identifier'           => $lockedWallet->account_identifier,
                'entry_type'                   => LedgerEntryType::CREDIT->value,
                'amount'                       => $amount,
                'opening_balance'              => $balances['opening_balance'],
                'closing_balance'              => $balances['closing_balance'],
            ]);

            return $transaction;
        });
    }

    /**
     * Direct Debit: withdraw or deduct funds from a wallet.
     * Double-Entry: DEBIT Wallet -> CREDIT Contra (Withdrawal / Clearing Account).
     */
    public function debit(
        FlightB2BWallet|int $wallet,
        float $amount,
        string $reference,
        string $actor,
        ?string $contraAccount = 'SYSTEM:WITHDRAWAL',
        ?string $description = null,
        array $metadata = []
    ): FlightWalletTransaction {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Debit amount must be strictly greater than zero.');
        }

        $walletId = $wallet instanceof FlightB2BWallet ? $wallet->id : $wallet;

        return DB::transaction(function () use ($walletId, $amount, $reference, $actor, $contraAccount, $description, $metadata) {
            $lockedWallet = $this->lockWallet($walletId);
            $this->checkDuplicateReference($lockedWallet->tenant_id, $reference);

            if ($lockedWallet->available_credit < $amount) {
                throw new InsufficientBalanceException(
                    requestedAmount: $amount,
                    availableCredit: $lockedWallet->available_credit,
                    balance: (float) $lockedWallet->balance,
                    creditLimit: (float) $lockedWallet->credit_limit
                );
            }

            $balances = $lockedWallet->applyAtomicDebit($amount);
            $txnId = $this->generateTransactionId();
            $contra = $contraAccount ?? 'SYSTEM:WITHDRAWAL';

            $transaction = FlightWalletTransaction::create([
                'tenant_id'             => $lockedWallet->tenant_id,
                'transaction_id'        => $txnId,
                'reference'             => $reference,
                'type'                  => WalletTransactionType::DEBIT->value,
                'debit_account'         => $lockedWallet->account_identifier,
                'credit_account'        => $contra,
                'debit_wallet_id'       => $lockedWallet->id,
                'amount'                => $amount,
                'currency'              => $lockedWallet->currency,
                'opening_balance'       => $balances['opening_balance'],
                'closing_balance'       => $balances['closing_balance'],
                'debit_opening_balance' => $balances['opening_balance'],
                'debit_closing_balance' => $balances['closing_balance'],
                'actor'                 => $actor,
                'description'           => $description ?? sprintf('Wallet debit of %.2f %s', $amount, $lockedWallet->currency),
                'metadata'              => $metadata,
            ]);

            // Ledger Entry 1: Debit Wallet
            FlightWalletLedgerEntry::create([
                'tenant_id'                    => $lockedWallet->tenant_id,
                'flight_wallet_transaction_id' => $transaction->id,
                'wallet_id'                    => $lockedWallet->id,
                'account_identifier'           => $lockedWallet->account_identifier,
                'entry_type'                   => LedgerEntryType::DEBIT->value,
                'amount'                       => $amount,
                'opening_balance'              => $balances['opening_balance'],
                'closing_balance'              => $balances['closing_balance'],
            ]);

            // Ledger Entry 2: Credit Contra
            FlightWalletLedgerEntry::create([
                'tenant_id'                    => $lockedWallet->tenant_id,
                'flight_wallet_transaction_id' => $transaction->id,
                'account_identifier'           => $contra,
                'entry_type'                   => LedgerEntryType::CREDIT->value,
                'amount'                       => $amount,
                'opening_balance'              => 0.00,
                'closing_balance'              => $amount,
            ]);

            return $transaction;
        });
    }

    /**
     * Direct Credit: funds credited to a wallet from a system account.
     * Double-Entry: DEBIT Contra -> CREDIT Wallet.
     */
    public function credit(
        FlightB2BWallet|int $wallet,
        float $amount,
        string $reference,
        string $actor,
        ?string $contraAccount = 'SYSTEM:DIRECT_CREDIT',
        ?string $description = null,
        array $metadata = []
    ): FlightWalletTransaction {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Credit amount must be strictly greater than zero.');
        }

        $walletId = $wallet instanceof FlightB2BWallet ? $wallet->id : $wallet;

        return DB::transaction(function () use ($walletId, $amount, $reference, $actor, $contraAccount, $description, $metadata) {
            $lockedWallet = $this->lockWallet($walletId);
            $this->checkDuplicateReference($lockedWallet->tenant_id, $reference);

            $balances = $lockedWallet->applyAtomicCredit($amount);
            $txnId = $this->generateTransactionId();
            $contra = $contraAccount ?? 'SYSTEM:DIRECT_CREDIT';

            $transaction = FlightWalletTransaction::create([
                'tenant_id'              => $lockedWallet->tenant_id,
                'transaction_id'         => $txnId,
                'reference'              => $reference,
                'type'                   => WalletTransactionType::CREDIT->value,
                'debit_account'          => $contra,
                'credit_account'         => $lockedWallet->account_identifier,
                'credit_wallet_id'       => $lockedWallet->id,
                'amount'                 => $amount,
                'currency'               => $lockedWallet->currency,
                'opening_balance'        => $balances['opening_balance'],
                'closing_balance'        => $balances['closing_balance'],
                'credit_opening_balance' => $balances['opening_balance'],
                'credit_closing_balance' => $balances['closing_balance'],
                'actor'                  => $actor,
                'description'            => $description ?? sprintf('Wallet credit of %.2f %s', $amount, $lockedWallet->currency),
                'metadata'               => $metadata,
            ]);

            FlightWalletLedgerEntry::create([
                'tenant_id'                    => $lockedWallet->tenant_id,
                'flight_wallet_transaction_id' => $transaction->id,
                'account_identifier'           => $contra,
                'entry_type'                   => LedgerEntryType::DEBIT->value,
                'amount'                       => $amount,
                'opening_balance'              => 0.00,
                'closing_balance'              => $amount,
            ]);

            FlightWalletLedgerEntry::create([
                'tenant_id'                    => $lockedWallet->tenant_id,
                'flight_wallet_transaction_id' => $transaction->id,
                'wallet_id'                    => $lockedWallet->id,
                'account_identifier'           => $lockedWallet->account_identifier,
                'entry_type'                   => LedgerEntryType::CREDIT->value,
                'amount'                       => $amount,
                'opening_balance'              => $balances['opening_balance'],
                'closing_balance'              => $balances['closing_balance'],
            ]);

            return $transaction;
        });
    }

    /**
     * Booking Deduction: Fare deducted from agent/distributor wallet to airline/booking escrow.
     * Double-Entry: DEBIT Wallet -> CREDIT Escrow.
     */
    public function bookingDeduction(
        FlightB2BWallet|int $wallet,
        float $amount,
        string $bookingReference,
        string $actor,
        ?string $airlineEscrow = null,
        array $metadata = []
    ): FlightWalletTransaction {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Booking deduction must be strictly greater than zero.');
        }

        $walletId = $wallet instanceof FlightB2BWallet ? $wallet->id : $wallet;

        return DB::transaction(function () use ($walletId, $amount, $bookingReference, $actor, $airlineEscrow, $metadata) {
            $lockedWallet = $this->lockWallet($walletId);
            $this->checkDuplicateReference($lockedWallet->tenant_id, $bookingReference);

            if ($lockedWallet->available_credit < $amount) {
                throw new InsufficientBalanceException(
                    requestedAmount: $amount,
                    availableCredit: $lockedWallet->available_credit,
                    balance: (float) $lockedWallet->balance,
                    creditLimit: (float) $lockedWallet->credit_limit
                );
            }

            $balances = $lockedWallet->applyAtomicDebit($amount);
            $txnId = $this->generateTransactionId();
            $escrow = $airlineEscrow ?? ('ESCROW:' . $bookingReference);

            $transaction = FlightWalletTransaction::create([
                'tenant_id'             => $lockedWallet->tenant_id,
                'transaction_id'        => $txnId,
                'reference'             => $bookingReference,
                'type'                  => WalletTransactionType::BOOKING_DEDUCTION->value,
                'debit_account'         => $lockedWallet->account_identifier,
                'credit_account'        => $escrow,
                'debit_wallet_id'       => $lockedWallet->id,
                'amount'                => $amount,
                'currency'              => $lockedWallet->currency,
                'opening_balance'       => $balances['opening_balance'],
                'closing_balance'       => $balances['closing_balance'],
                'debit_opening_balance' => $balances['opening_balance'],
                'debit_closing_balance' => $balances['closing_balance'],
                'actor'                 => $actor,
                'description'           => sprintf('Booking deduction for PNR/Ref: %s', $bookingReference),
                'metadata'              => array_merge($metadata, ['booking_reference' => $bookingReference]),
            ]);

            FlightWalletLedgerEntry::create([
                'tenant_id'                    => $lockedWallet->tenant_id,
                'flight_wallet_transaction_id' => $transaction->id,
                'wallet_id'                    => $lockedWallet->id,
                'account_identifier'           => $lockedWallet->account_identifier,
                'entry_type'                   => LedgerEntryType::DEBIT->value,
                'amount'                       => $amount,
                'opening_balance'              => $balances['opening_balance'],
                'closing_balance'              => $balances['closing_balance'],
            ]);

            FlightWalletLedgerEntry::create([
                'tenant_id'                    => $lockedWallet->tenant_id,
                'flight_wallet_transaction_id' => $transaction->id,
                'account_identifier'           => $escrow,
                'entry_type'                   => LedgerEntryType::CREDIT->value,
                'amount'                       => $amount,
                'opening_balance'              => 0.00,
                'closing_balance'              => $amount,
            ]);

            event(new \Modules\Flight\Events\FlightWalletDebited($transaction, $lockedWallet));

            return $transaction;
        });
    }

    /**
     * Refund Credit: cancelled booking refunded back to agent/distributor wallet.
     * Double-Entry: DEBIT Escrow -> CREDIT Wallet.
     * Replenishes credit_used line first before topping up liquid cash balance.
     */
    public function refundCredit(
        FlightB2BWallet|int $wallet,
        float $amount,
        string $refundReference,
        string $actor,
        ?string $bookingReference = null,
        array $metadata = []
    ): FlightWalletTransaction {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Refund amount must be strictly greater than zero.');
        }

        $walletId = $wallet instanceof FlightB2BWallet ? $wallet->id : $wallet;

        return DB::transaction(function () use ($walletId, $amount, $refundReference, $actor, $bookingReference, $metadata) {
            $lockedWallet = $this->lockWallet($walletId);
            $this->checkDuplicateReference($lockedWallet->tenant_id, $refundReference);

            $balances = $lockedWallet->applyAtomicCredit($amount);
            $txnId = $this->generateTransactionId();
            $escrow = $bookingReference ? ('ESCROW:' . $bookingReference) : 'SYSTEM:REFUND_POOL';

            $transaction = FlightWalletTransaction::create([
                'tenant_id'              => $lockedWallet->tenant_id,
                'transaction_id'         => $txnId,
                'reference'              => $refundReference,
                'type'                   => WalletTransactionType::REFUND->value,
                'debit_account'          => $escrow,
                'credit_account'         => $lockedWallet->account_identifier,
                'credit_wallet_id'       => $lockedWallet->id,
                'amount'                 => $amount,
                'currency'               => $lockedWallet->currency,
                'opening_balance'        => $balances['opening_balance'],
                'closing_balance'        => $balances['closing_balance'],
                'credit_opening_balance' => $balances['opening_balance'],
                'credit_closing_balance' => $balances['closing_balance'],
                'actor'                  => $actor,
                'description'            => sprintf('Refund credit for ref: %s', $refundReference),
                'metadata'               => array_merge($metadata, [
                    'refund_reference'  => $refundReference,
                    'booking_reference' => $bookingReference,
                ]),
            ]);

            FlightWalletLedgerEntry::create([
                'tenant_id'                    => $lockedWallet->tenant_id,
                'flight_wallet_transaction_id' => $transaction->id,
                'account_identifier'           => $escrow,
                'entry_type'                   => LedgerEntryType::DEBIT->value,
                'amount'                       => $amount,
                'opening_balance'              => 0.00,
                'closing_balance'              => $amount,
            ]);

            FlightWalletLedgerEntry::create([
                'tenant_id'                    => $lockedWallet->tenant_id,
                'flight_wallet_transaction_id' => $transaction->id,
                'wallet_id'                    => $lockedWallet->id,
                'account_identifier'           => $lockedWallet->account_identifier,
                'entry_type'                   => LedgerEntryType::CREDIT->value,
                'amount'                       => $amount,
                'opening_balance'              => $balances['opening_balance'],
                'closing_balance'              => $balances['closing_balance'],
            ]);

            event(new \Modules\Flight\Events\FlightWalletRefunded($transaction, $lockedWallet));

            return $transaction;
        });
    }

    /**
     * Commission Credit: earned commission credited to agent/distributor wallet.
     * Double-Entry: DEBIT Tenant Commission Expense -> CREDIT Wallet.
     */
    public function commissionCredit(
        FlightB2BWallet|int $wallet,
        float $amount,
        string $commissionReference,
        string $actor,
        ?string $sourceAccount = 'TENANT:COMMISSION_EXPENSE',
        array $metadata = []
    ): FlightWalletTransaction {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Commission credit amount must be strictly greater than zero.');
        }

        $walletId = $wallet instanceof FlightB2BWallet ? $wallet->id : $wallet;

        return DB::transaction(function () use ($walletId, $amount, $commissionReference, $actor, $sourceAccount, $metadata) {
            $lockedWallet = $this->lockWallet($walletId);
            $this->checkDuplicateReference($lockedWallet->tenant_id, $commissionReference);

            $balances = $lockedWallet->applyAtomicCredit($amount);
            $txnId = $this->generateTransactionId();
            $source = $sourceAccount ?? 'TENANT:COMMISSION_EXPENSE';

            $transaction = FlightWalletTransaction::create([
                'tenant_id'              => $lockedWallet->tenant_id,
                'transaction_id'         => $txnId,
                'reference'              => $commissionReference,
                'type'                   => WalletTransactionType::COMMISSION->value,
                'debit_account'          => $source,
                'credit_account'         => $lockedWallet->account_identifier,
                'credit_wallet_id'       => $lockedWallet->id,
                'amount'                 => $amount,
                'currency'               => $lockedWallet->currency,
                'opening_balance'        => $balances['opening_balance'],
                'closing_balance'        => $balances['closing_balance'],
                'credit_opening_balance' => $balances['opening_balance'],
                'credit_closing_balance' => $balances['closing_balance'],
                'actor'                  => $actor,
                'description'            => sprintf('Commission payout for %s', $commissionReference),
                'metadata'               => $metadata,
            ]);

            FlightWalletLedgerEntry::create([
                'tenant_id'                    => $lockedWallet->tenant_id,
                'flight_wallet_transaction_id' => $transaction->id,
                'account_identifier'           => $source,
                'entry_type'                   => LedgerEntryType::DEBIT->value,
                'amount'                       => $amount,
                'opening_balance'              => 0.00,
                'closing_balance'              => $amount,
            ]);

            FlightWalletLedgerEntry::create([
                'tenant_id'                    => $lockedWallet->tenant_id,
                'flight_wallet_transaction_id' => $transaction->id,
                'wallet_id'                    => $lockedWallet->id,
                'account_identifier'           => $lockedWallet->account_identifier,
                'entry_type'                   => LedgerEntryType::CREDIT->value,
                'amount'                       => $amount,
                'opening_balance'              => $balances['opening_balance'],
                'closing_balance'              => $balances['closing_balance'],
            ]);

            return $transaction;
        });
    }

    /**
     * Manual Adjustment: Positive adds funds (Credit), Negative deducts funds (Debit).
     */
    public function manualAdjustment(
        FlightB2BWallet|int $wallet,
        float $amount,
        string $reason,
        string $actor,
        string $reference,
        array $metadata = []
    ): FlightWalletTransaction {
        $amount = round($amount, 2);
        if ($amount == 0.0) {
            throw new InvalidArgumentException('Adjustment amount cannot be zero.');
        }

        $walletId = $wallet instanceof FlightB2BWallet ? $wallet->id : $wallet;

        return DB::transaction(function () use ($walletId, $amount, $reason, $actor, $reference, $metadata) {
            $lockedWallet = $this->lockWallet($walletId);
            $this->checkDuplicateReference($lockedWallet->tenant_id, $reference);

            $contra = 'SYSTEM:MANUAL_ADJUSTMENT';
            $txnId = $this->generateTransactionId();

            if ($amount > 0) {
                $balances = $lockedWallet->applyAtomicCredit($amount);
                $debitAcc = $contra;
                $creditAcc = $lockedWallet->account_identifier;
                $debitWalletId = null;
                $creditWalletId = $lockedWallet->id;
            } else {
                $absAmount = abs($amount);
                if ($lockedWallet->available_credit < $absAmount) {
                    throw new InsufficientBalanceException(
                        requestedAmount: $absAmount,
                        availableCredit: $lockedWallet->available_credit,
                        balance: (float) $lockedWallet->balance,
                        creditLimit: (float) $lockedWallet->credit_limit
                    );
                }
                $balances = $lockedWallet->applyAtomicDebit($absAmount);
                $debitAcc = $lockedWallet->account_identifier;
                $creditAcc = $contra;
                $debitWalletId = $lockedWallet->id;
                $creditWalletId = null;
            }

            $effectiveAmount = abs($amount);

            $transaction = FlightWalletTransaction::create([
                'tenant_id'              => $lockedWallet->tenant_id,
                'transaction_id'         => $txnId,
                'reference'              => $reference,
                'type'                   => WalletTransactionType::ADJUSTMENT->value,
                'debit_account'          => $debitAcc,
                'credit_account'         => $creditAcc,
                'debit_wallet_id'        => $debitWalletId,
                'credit_wallet_id'       => $creditWalletId,
                'amount'                 => $effectiveAmount,
                'currency'               => $lockedWallet->currency,
                'opening_balance'        => $balances['opening_balance'],
                'closing_balance'        => $balances['closing_balance'],
                'actor'                  => $actor,
                'description'            => sprintf('Manual adjustment (%s): %s', $amount > 0 ? 'Credit' : 'Debit', $reason),
                'metadata'               => array_merge($metadata, ['reason' => $reason, 'signed_amount' => $amount]),
            ]);

            FlightWalletLedgerEntry::create([
                'tenant_id'                    => $lockedWallet->tenant_id,
                'flight_wallet_transaction_id' => $transaction->id,
                'wallet_id'                    => $debitWalletId,
                'account_identifier'           => $debitAcc,
                'entry_type'                   => LedgerEntryType::DEBIT->value,
                'amount'                       => $effectiveAmount,
                'opening_balance'              => $debitWalletId ? $balances['opening_balance'] : 0.00,
                'closing_balance'              => $debitWalletId ? $balances['closing_balance'] : $effectiveAmount,
            ]);

            FlightWalletLedgerEntry::create([
                'tenant_id'                    => $lockedWallet->tenant_id,
                'flight_wallet_transaction_id' => $transaction->id,
                'wallet_id'                    => $creditWalletId,
                'account_identifier'           => $creditAcc,
                'entry_type'                   => LedgerEntryType::CREDIT->value,
                'amount'                       => $effectiveAmount,
                'opening_balance'              => $creditWalletId ? $balances['opening_balance'] : 0.00,
                'closing_balance'              => $creditWalletId ? $balances['closing_balance'] : $effectiveAmount,
            ]);

            return $transaction;
        });
    }

    /**
     * Inter-Wallet Transfer: Move funds from one wallet to another.
     * Locks wallets deterministically in ascending ID order to prevent deadlocks.
     */
    public function transfer(
        FlightB2BWallet|int $fromWallet,
        FlightB2BWallet|int $toWallet,
        float $amount,
        string $reference,
        string $actor,
        ?string $description = null
    ): FlightWalletTransaction {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Transfer amount must be strictly greater than zero.');
        }

        $fromId = $fromWallet instanceof FlightB2BWallet ? $fromWallet->id : $fromWallet;
        $toId = $toWallet instanceof FlightB2BWallet ? $toWallet->id : $toWallet;

        if ($fromId === $toId) {
            throw new InvalidArgumentException('Source and destination wallets must be different.');
        }

        return DB::transaction(function () use ($fromId, $toId, $amount, $reference, $actor, $description) {
            // Deterministic row locking order
            $firstId = min($fromId, $toId);
            $secondId = max($fromId, $toId);

            $this->lockWallet($firstId);
            $this->lockWallet($secondId);

            $lockedFrom = FlightB2BWallet::findOrFail($fromId);
            $lockedTo = FlightB2BWallet::findOrFail($toId);

            if ($lockedFrom->tenant_id !== $lockedTo->tenant_id) {
                throw new InvalidArgumentException('Transfers across differing tenants are strictly prohibited.');
            }

            $this->checkDuplicateReference($lockedFrom->tenant_id, $reference);

            if ($lockedFrom->available_credit < $amount) {
                throw new InsufficientBalanceException(
                    requestedAmount: $amount,
                    availableCredit: $lockedFrom->available_credit,
                    balance: (float) $lockedFrom->balance,
                    creditLimit: (float) $lockedFrom->credit_limit
                );
            }

            $fromBalances = $lockedFrom->applyAtomicDebit($amount);
            $toBalances = $lockedTo->applyAtomicCredit($amount);
            $txnId = $this->generateTransactionId();

            $transaction = FlightWalletTransaction::create([
                'tenant_id'              => $lockedFrom->tenant_id,
                'transaction_id'         => $txnId,
                'reference'              => $reference,
                'type'                   => WalletTransactionType::TRANSFER->value,
                'debit_account'          => $lockedFrom->account_identifier,
                'credit_account'         => $lockedTo->account_identifier,
                'debit_wallet_id'        => $lockedFrom->id,
                'credit_wallet_id'       => $lockedTo->id,
                'amount'                 => $amount,
                'currency'               => $lockedFrom->currency,
                'opening_balance'        => $fromBalances['opening_balance'],
                'closing_balance'        => $fromBalances['closing_balance'],
                'debit_opening_balance'  => $fromBalances['opening_balance'],
                'debit_closing_balance'  => $fromBalances['closing_balance'],
                'credit_opening_balance' => $toBalances['opening_balance'],
                'credit_closing_balance' => $toBalances['closing_balance'],
                'actor'                  => $actor,
                'description'            => $description ?? sprintf('Transfer from %s to %s', $lockedFrom->account_identifier, $lockedTo->account_identifier),
            ]);

            // Ledger Entry 1: Debit Source Wallet
            FlightWalletLedgerEntry::create([
                'tenant_id'                    => $lockedFrom->tenant_id,
                'flight_wallet_transaction_id' => $transaction->id,
                'wallet_id'                    => $lockedFrom->id,
                'account_identifier'           => $lockedFrom->account_identifier,
                'entry_type'                   => LedgerEntryType::DEBIT->value,
                'amount'                       => $amount,
                'opening_balance'              => $fromBalances['opening_balance'],
                'closing_balance'              => $fromBalances['closing_balance'],
            ]);

            // Ledger Entry 2: Credit Destination Wallet
            FlightWalletLedgerEntry::create([
                'tenant_id'                    => $lockedTo->tenant_id,
                'flight_wallet_transaction_id' => $transaction->id,
                'wallet_id'                    => $lockedTo->id,
                'account_identifier'           => $lockedTo->account_identifier,
                'entry_type'                   => LedgerEntryType::CREDIT->value,
                'amount'                       => $amount,
                'opening_balance'              => $toBalances['opening_balance'],
                'closing_balance'              => $toBalances['closing_balance'],
            ]);

            return $transaction;
        });
    }

    /**
     * Adjust credit limit for a wallet.
     * Enforces that the new limit cannot be lower than active credit currently drawn.
     */
    public function adjustCreditLimit(
        FlightB2BWallet|int $wallet,
        float $newLimit,
        string $actor,
        string $reference
    ): FlightB2BWallet {
        $newLimit = round(max(0.0, $newLimit), 2);
        $walletId = $wallet instanceof FlightB2BWallet ? $wallet->id : $wallet;

        return DB::transaction(function () use ($walletId, $newLimit) {
            $lockedWallet = $this->lockWallet($walletId);

            if ($newLimit < $lockedWallet->credit_used) {
                throw new CreditLimitExceededException(
                    sprintf(
                        'Cannot reduce credit limit to %.2f while %.2f is currently utilized. Outstanding credit must be repaid first.',
                        $newLimit,
                        $lockedWallet->credit_used
                    )
                );
            }

            $lockedWallet->update(['credit_limit' => $newLimit]);
            return $lockedWallet->fresh();
        });
    }

    /**
     * Audit: verify mathematical consistency across the double-entry ledger.
     * Checks that:
     *  1. Total debits == Total credits across all ledger entries
     *  2. Every transaction has balanced debit and credit entries
     */
    public function verifyLedgerConsistency(?int $tenantId = null): array
    {
        $entriesQuery = FlightWalletLedgerEntry::query();
        $txnsQuery = FlightWalletTransaction::query();

        if ($tenantId !== null) {
            $entriesQuery->where('tenant_id', $tenantId);
            $txnsQuery->where('tenant_id', $tenantId);
        }

        $totalDebits = (float) (clone $entriesQuery)->where('entry_type', LedgerEntryType::DEBIT->value)->sum('amount');
        $totalCredits = (float) (clone $entriesQuery)->where('entry_type', LedgerEntryType::CREDIT->value)->sum('amount');
        $totalEntries = (clone $entriesQuery)->count();
        $totalTransactions = (clone $txnsQuery)->count();

        $imbalance = round(abs($totalDebits - $totalCredits), 4);
        $isConsistent = ($imbalance === 0.0);

        // Check transaction-level balance
        $unbalancedTxns = 0;
        $transactions = (clone $txnsQuery)->with('ledgerEntries')->get();

        foreach ($transactions as $txn) {
            $txnDebits = $txn->ledgerEntries->where('entry_type', LedgerEntryType::DEBIT->value)->sum('amount');
            $txnCredits = $txn->ledgerEntries->where('entry_type', LedgerEntryType::CREDIT->value)->sum('amount');

            if (round(abs($txnDebits - $txnCredits), 4) !== 0.0 || round(abs($txnDebits - $txn->amount), 4) !== 0.0) {
                $unbalancedTxns++;
            }
        }

        return [
            'is_consistent'        => $isConsistent && ($unbalancedTxns === 0),
            'total_debits'         => $totalDebits,
            'total_credits'        => $totalCredits,
            'imbalance'            => $imbalance,
            'total_transactions'   => $totalTransactions,
            'total_ledger_entries' => $totalEntries,
            'unbalanced_txns'      => $unbalancedTxns,
        ];
    }

    /**
     * Row-level lock acquisition.
     */
    protected function lockWallet(int $walletId): FlightB2BWallet
    {
        $wallet = FlightB2BWallet::where('id', $walletId)->lockForUpdate()->firstOrFail();

        if (! $wallet->is_active) {
            throw new WalletInactiveException();
        }

        return $wallet;
    }

    /**
     * Idempotency protection against duplicate financial transactions.
     */
    protected function checkDuplicateReference(int $tenantId, string $reference): void
    {
        $exists = FlightWalletTransaction::where('tenant_id', $tenantId)
            ->where('reference', $reference)
            ->exists();

        if ($exists) {
            throw new DuplicateTransactionException($reference);
        }
    }

    /**
     * Format a standardized unique transaction identifier.
     */
    protected function generateTransactionId(): string
    {
        return 'TXN-' . date('Ymd') . '-' . strtoupper(Str::random(10));
    }
}
