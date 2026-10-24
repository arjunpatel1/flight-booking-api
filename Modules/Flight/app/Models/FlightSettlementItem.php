<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class FlightSettlementItem extends Model
{
    use BelongsToTenant;

    protected $table = 'flight_settlement_items';

    protected $fillable = [
        'flight_settlement_batch_id',
        'tenant_id',
        'flight_booking_id',
        'booking_reference',
        'supplier_code',
        'distributor_id',
        'agent_id',
        'booking_amount',
        'supplier_payable',
        'platform_revenue',
        'tenant_revenue',
        'distributor_commission',
        'agent_commission',
        'taxes',
        'fees',
        'currency',
        'status',
        'reconciliation_status',
        'has_mismatch',
        'reconciliation_details',
        'calculation_breakdown',
    ];

    protected $casts = [
        'booking_amount'         => 'decimal:2',
        'supplier_payable'       => 'decimal:2',
        'platform_revenue'       => 'decimal:2',
        'tenant_revenue'         => 'decimal:2',
        'distributor_commission' => 'decimal:2',
        'agent_commission'       => 'decimal:2',
        'taxes'                  => 'decimal:2',
        'fees'                   => 'decimal:2',
        'has_mismatch'           => 'boolean',
        'reconciliation_details' => 'array',
        'calculation_breakdown'  => 'array',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function batch(): BelongsTo
    {
        return $this->belongsTo(FlightSettlementBatch::class, 'flight_settlement_batch_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function mismatches(): HasMany
    {
        return $this->hasMany(FlightSettlementMismatch::class, 'flight_settlement_item_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForBatch(Builder $query, int $batchId): void
    {
        $query->where('flight_settlement_batch_id', $batchId);
    }

    public function scopeWithMismatches(Builder $query): void
    {
        $query->where('has_mismatch', true);
    }
}
