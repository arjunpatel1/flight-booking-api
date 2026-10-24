<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Flight\Enums\AllocationStatus;
use Modules\Flight\Enums\HolderType;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

/**
 * Phase 4: Inventory Allocation
 *
 * One row per allocation event in the hierarchy.
 * parent_allocation_id prevents a child from receiving more than the parent owns.
 *
 * Hierarchy: Central → Tenant → Distributor → Agent → B2C
 */
class FlightInventoryAllocation extends Model
{
    use BelongsToTenant, HasFilters, HasSortBy, SoftDeletes;

    protected $table = 'flight_inventory_allocations';

    protected $fillable = [
        'allocation_ref',
        'tenant_id',
        'flight_inventory_id',
        'cabin_class',
        'from_holder_type',
        'from_holder_id',
        'to_holder_type',
        'to_holder_id',
        'parent_allocation_id',
        'seats_requested',
        'seats_allocated',
        'allocation_mode',
        'floor_seats',
        'status',
        'requested_by',
        'approved_by',
        'processed_by',
        'requested_at',
        'approved_at',
        'completed_at',
        'expires_at',
        'notes',
        'rejection_reason',
        'meta',
    ];

    protected $casts = [
        'seats_requested'   => 'integer',
        'seats_allocated'   => 'integer',
        'floor_seats'       => 'integer',
        'status'            => AllocationStatus::class,
        'from_holder_type'  => HolderType::class,
        'to_holder_type'    => HolderType::class,
        'requested_at'      => 'datetime',
        'approved_at'       => 'datetime',
        'completed_at'      => 'datetime',
        'expires_at'        => 'datetime',
        'meta'              => 'array',
    ];

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->allocation_ref)) {
                $model->allocation_ref = (string) \Str::uuid();
            }
            if (empty($model->requested_at)) {
                $model->requested_at = now();
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function flightInventory(): BelongsTo
    {
        return $this->belongsTo(FlightInventory::class, 'flight_inventory_id');
    }

    public function parentAllocation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_allocation_id');
    }

    public function childAllocations(): HasMany
    {
        return $this->hasMany(self::class, 'parent_allocation_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FlightInventoryTransaction::class, 'allocation_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeByStatus(Builder $query, AllocationStatus $status): void
    {
        $query->where('status', $status);
    }

    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', [
            AllocationStatus::REQUESTED->value,
            AllocationStatus::APPROVED->value,
            AllocationStatus::PROCESSING->value,
        ]);
    }

    public function scopeExpired(Builder $query): void
    {
        $query->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->where('status', AllocationStatus::COMPLETED->value);
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->where(function (Builder $q) use ($value) {
            $q->whereLike('allocation_ref', "%{$value}%")
                ->orWhereLike('notes', "%{$value}%");
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isEditable(): bool
    {
        return $this->status === AllocationStatus::REQUESTED;
    }

    public function canApprove(): bool
    {
        return $this->status->canTransitionTo(AllocationStatus::APPROVED);
    }

    public function canReject(): bool
    {
        return $this->status->canTransitionTo(AllocationStatus::REJECTED);
    }

    public function canCancel(): bool
    {
        return $this->status->canTransitionTo(AllocationStatus::CANCELLED);
    }

    public function canReverse(): bool
    {
        return $this->status->canTransitionTo(AllocationStatus::REVERSED);
    }

    /** Total child allocations that have been COMPLETED for this allocation. */
    public function allocatedToChildren(): int
    {
        return (int) $this->childAllocations()
            ->where('status', AllocationStatus::COMPLETED->value)
            ->sum('seats_allocated');
    }

    /** Remaining seats available for further child allocations. */
    public function remainingForChildren(): int
    {
        return max(0, $this->seats_allocated - $this->allocatedToChildren());
    }

    // ── Filter / Sort ─────────────────────────────────────────────────────────

    public function allowedFilterKeys(): array
    {
        return [
            'search',
            'status',
            'cabin_class',
            'from_holder_type',
            'to_holder_type',
            'allocation_mode',
        ];
    }

    protected function getSortableAttributes(): array
    {
        return [
            'allocation_ref',
            'seats_requested',
            'seats_allocated',
            'status',
            'requested_at',
            'approved_at',
            'completed_at',
            'expires_at',
            'created_at',
        ];
    }
}
