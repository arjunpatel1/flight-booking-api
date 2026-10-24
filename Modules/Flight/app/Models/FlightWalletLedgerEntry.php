<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class FlightWalletLedgerEntry extends Model
{
    use BelongsToTenant;

    protected $table = 'flight_wallet_ledger_entries';

    protected $fillable = [
        'tenant_id',
        'flight_wallet_transaction_id',
        'wallet_id',
        'account_identifier',
        'entry_type',
        'amount',
        'opening_balance',
        'closing_balance',
    ];

    protected $casts = [
        'amount'          => 'float',
        'opening_balance' => 'float',
        'closing_balance' => 'float',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FlightWalletTransaction::class, 'flight_wallet_transaction_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(FlightB2BWallet::class, 'wallet_id');
    }
}
