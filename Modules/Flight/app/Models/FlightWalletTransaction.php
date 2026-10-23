<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Flight\Enums\WalletTransactionType;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class FlightWalletTransaction extends Model
{
    use BelongsToTenant;

    protected $table = 'flight_wallet_transactions';

    protected $fillable = [
        'tenant_id',
        'transaction_id',
        'reference',
        'type',
        'debit_account',
        'credit_account',
        'debit_wallet_id',
        'credit_wallet_id',
        'amount',
        'currency',
        'opening_balance',
        'closing_balance',
        'debit_opening_balance',
        'debit_closing_balance',
        'credit_opening_balance',
        'credit_closing_balance',
        'actor',
        'description',
        'metadata',
    ];

    protected $casts = [
        'amount'                 => 'float',
        'opening_balance'        => 'float',
        'closing_balance'        => 'float',
        'debit_opening_balance'  => 'float',
        'debit_closing_balance'  => 'float',
        'credit_opening_balance' => 'float',
        'credit_closing_balance' => 'float',
        'metadata'               => 'array',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function debitWallet(): BelongsTo
    {
        return $this->belongsTo(FlightB2BWallet::class, 'debit_wallet_id');
    }

    public function creditWallet(): BelongsTo
    {
        return $this->belongsTo(FlightB2BWallet::class, 'credit_wallet_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(FlightWalletLedgerEntry::class, 'flight_wallet_transaction_id');
    }

    public function getFormattedTypeAttribute(): string
    {
        return WalletTransactionType::tryFrom($this->type)?->label() ?? $this->type;
    }
}
