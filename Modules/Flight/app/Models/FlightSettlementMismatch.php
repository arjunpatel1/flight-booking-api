<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Flight\Enums\SettlementMismatchSeverity;
use Modules\Flight\Enums\SettlementMismatchType;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class FlightSettlementMismatch extends Model
{
    use BelongsToTenant;

    protected $table = 'flight_settlement_mismatches';

    protected $fillable = [
        'flight_settlement_batch_id',
        'flight_settlement_item_id',
        'flight_booking_id',
        'booking_reference',
        'tenant_id',
        'mismatch_type',
        'source_entity',
        'expected_amount',
        'actual_amount',
        'discrepancy_amount',
        'severity',
        'status',
        'details',
        'resolved_at',
        'resolved_by',
        'resolution_notes',
    ];

    protected $casts = [
        'expected_amount'    => 'decimal:2',
        'actual_amount'      => 'decimal:2',
        'discrepancy_amount' => 'decimal:2',
        'details'            => 'array',
        'resolved_at'        => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function batch(): BelongsTo
    {
        return $this->belongsTo(FlightSettlementBatch::class, 'flight_settlement_batch_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(FlightSettlementItem::class, 'flight_settlement_item_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeUnresolved(Builder $query): void
    {
        $query->where('status', 'UNRESOLVED');
    }

    public function scopeForBatch(Builder $query, int $batchId): void
    {
        $query->where('flight_settlement_batch_id', $batchId);
    }

    public function scopeByType(Builder $query, string $type): void
    {
        $query->where('mismatch_type', $type);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    public function resolve(int $userId, string $notes): void
    {
        $this->status = 'RESOLVED';
        $this->resolved_by = $userId;
        $this->resolved_at = now();
        $this->resolution_notes = $notes;
        $this->save();
    }
}
