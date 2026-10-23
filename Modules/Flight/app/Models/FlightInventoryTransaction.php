<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Flight\Enums\HolderType;
use Modules\Flight\Enums\TransactionType;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

/**
 * Phase 4: Inventory Transaction (Ledger)
 *
 * Append-only audit ledger.  Every seat movement writes one row.
 * Never update or delete rows.  Use ADJUSTMENT/RECONCILIATION for corrections.
 */
class FlightInventoryTransaction extends Model
{
    use BelongsToTenant, HasFilters, HasSortBy;

    protected $table = 'flight_inventory_transactions';

    // Immutable — no updated_at
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'transaction_ref',
        'tenant_id',
        'flight_inventory_id',
        'cabin_class',
        'allocation_id',
        'transaction_type',
        'from_holder_type',
        'from_holder_id',
        'to_holder_type',
        'to_holder_id',
        'seats_delta',
        'from_balance_before',
        'from_balance_after',
        'to_balance_before',
        'to_balance_after',
        'actor_id',
        'actor_ip',
        'reason',
        'meta',
    ];

    protected $casts = [
        'seats_delta'        => 'integer',
        'from_balance_before'=> 'integer',
        'from_balance_after' => 'integer',
        'to_balance_before'  => 'integer',
        'to_balance_after'   => 'integer',
        'transaction_type'   => TransactionType::class,
        'from_holder_type'   => HolderType::class,
        'to_holder_type'     => HolderType::class,
        'created_at'         => 'datetime',
        'meta'               => 'array',
    ];

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->transaction_ref)) {
                $model->transaction_ref = (string) \Str::uuid();
            }
        });

        // Prevent all updates and deletes — ledger is immutable
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function flightInventory(): BelongsTo
    {
        return $this->belongsTo(FlightInventory::class, 'flight_inventory_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(FlightInventoryAllocation::class, 'allocation_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeByType(Builder $query, TransactionType $type): void
    {
        $query->where('transaction_type', $type->value);
    }

    public function scopeForHolder(Builder $query, string $holderType, ?int $holderId = null): void
    {
        $query->where(function (Builder $q) use ($holderType, $holderId) {
            $q->where(function (Builder $inner) use ($holderType, $holderId) {
                $inner->where('from_holder_type', $holderType);
                if ($holderId !== null) {
                    $inner->where('from_holder_id', $holderId);
                }
            })->orWhere(function (Builder $inner) use ($holderType, $holderId) {
                $inner->where('to_holder_type', $holderType);
                if ($holderId !== null) {
                    $inner->where('to_holder_id', $holderId);
                }
            });
        });
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->where(function (Builder $q) use ($value) {
            $q->whereLike('transaction_ref', "%{$value}%")
                ->orWhereLike('reason', "%{$value}%");
        });
    }

    public function scopeInDateRange(Builder $query, ?string $from, ?string $to): void
    {
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getIsDebitAttribute(): bool
    {
        return $this->seats_delta < 0;
    }

    public function getIsCreditAttribute(): bool
    {
        return $this->seats_delta > 0;
    }

    // ── Filter / Sort ─────────────────────────────────────────────────────────

    public function allowedFilterKeys(): array
    {
        return [
            'search',
            'transaction_type',
            'cabin_class',
            'from_holder_type',
            'to_holder_type',
            'date_from',
            'date_to',
        ];
    }

    protected function getSortableAttributes(): array
    {
        return [
            'transaction_ref',
            'transaction_type',
            'seats_delta',
            'created_at',
        ];
    }
}
