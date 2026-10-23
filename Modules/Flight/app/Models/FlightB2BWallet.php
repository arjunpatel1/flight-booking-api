<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Flight\Services\Wallet\FlightWalletLedgerService;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class FlightB2BWallet extends Model
{
    use BelongsToTenant;

    protected $table = 'flight_b2b_wallets';

    protected $fillable = [
        'tenant_id',
        'owner_type',
        'owner_id',
        'account_number',
        'currency',
        'balance',
        'credit_limit',
        'credit_used',
        'is_active',
    ];

    protected $casts = [
        'balance'      => 'float',
        'credit_limit' => 'float',
        'credit_used'  => 'float',
        'is_active'    => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function debitTransactions(): HasMany
    {
        return $this->hasMany(FlightWalletTransaction::class, 'debit_wallet_id');
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(FlightWalletTransaction::class, 'credit_wallet_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(FlightWalletLedgerEntry::class, 'wallet_id');
    }

    public function transactions(): HasMany
    {
        return $this->ledgerEntries();
    }

    public function getAccountIdentifierAttribute(): string
    {
        if (! empty($this->account_number)) {
            return $this->account_number;
        }

        return sprintf('WALLET:%s-%d', substr($this->owner_type, 0, 3), $this->owner_id);
    }

    public function getAvailableCreditAttribute(): float
    {
        $totalPurchasingPower = $this->balance + $this->credit_limit;
        return round(max(0.0, $totalPurchasingPower - $this->credit_used), 2);
    }

    public function getPurchasingPowerAttribute(): float
    {
        return $this->available_credit;
    }

    public function canDebit(float $amount): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->available_credit >= $amount;
    }

    /**
     * Ledger-compliant debit: guarantees row locking and double-entry ledger entry creation.
     */
    public function debit(
        float $amount,
        ?string $reference = null,
        string $actor = 'System',
        ?string $contraAccount = null,
        ?string $description = null
    ): bool {
        if (! $this->canDebit($amount)) {
            return false;
        }

        $ledgerService = app(FlightWalletLedgerService::class);
        $ref = $reference ?? ('DEBIT-' . uniqid('', true));

        try {
            $ledgerService->debit(
                wallet: $this,
                amount: $amount,
                reference: $ref,
                actor: $actor,
                contraAccount: $contraAccount,
                description: $description
            );
            $this->refresh();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Ledger-compliant credit: guarantees row locking and double-entry ledger entry creation.
     */
    public function credit(
        float $amount,
        ?string $reference = null,
        string $actor = 'System',
        ?string $contraAccount = null,
        ?string $description = null
    ): bool {
        $ledgerService = app(FlightWalletLedgerService::class);
        $ref = $reference ?? ('CREDIT-' . uniqid('', true));

        try {
            $ledgerService->credit(
                wallet: $this,
                amount: $amount,
                reference: $ref,
                actor: $actor,
                contraAccount: $contraAccount,
                description: $description
            );
            $this->refresh();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Internal atomic balance modification executed within row-locked DB transaction by LedgerService.
     */
    public function applyAtomicDebit(float $amount): array
    {
        $openingBalance = (float) $this->balance;

        if ($this->balance >= $amount) {
            $this->decrement('balance', $amount);
        } else {
            $remainder = $amount - $this->balance;
            $this->update(['balance' => 0.0]);
            $this->increment('credit_used', $remainder);
        }

        $this->refresh();
        $closingBalance = (float) $this->balance;

        return [
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
        ];
    }

    /**
     * Internal atomic balance replenishment executed within row-locked DB transaction by LedgerService.
     */
    public function applyAtomicCredit(float $amount): array
    {
        $openingBalance = (float) $this->balance;

        if ($this->credit_used > 0) {
            if ($this->credit_used >= $amount) {
                $this->decrement('credit_used', $amount);
            } else {
                $surplus = $amount - $this->credit_used;
                $this->update(['credit_used' => 0.0]);
                $this->increment('balance', $surplus);
            }
        } else {
            $this->increment('balance', $amount);
        }

        $this->refresh();
        $closingBalance = (float) $this->balance;

        return [
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
        ];
    }
}
