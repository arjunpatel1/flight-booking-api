<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Flight\Enums\HolderType;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

/**
 * Phase 4: Inventory Balance
 *
 * The real-time seat counter for each holder in the hierarchy.
 * One row per (flight_inventory, holder_type, holder_id, cabin_class).
 *
 * Rule: available + reserved + booked + frozen == total_allocated
 */
class FlightInventoryBalance extends Model
{
    use BelongsToTenant, HasFilters, HasSortBy;

    protected $table = 'flight_inventory_balances';

    protected $fillable = [
        'tenant_id',
        'flight_inventory_id',
        'holder_type',
        'holder_id',
        'cabin_class',
        'total_allocated',
        'available',
        'reserved',
        'booked',
        'frozen',
        'allocation_mode',
        'floor_seats',
        'is_active',
        'expires_at',
    ];

    protected $casts = [
        'total_allocated'  => 'integer',
        'available'        => 'integer',
        'reserved'         => 'integer',
        'booked'           => 'integer',
        'frozen'           => 'integer',
        'floor_seats'      => 'integer',
        'is_active'        => 'boolean',
        'expires_at'       => 'datetime',
        'holder_type'      => HolderType::class,
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function flightInventory(): BelongsTo
    {
        return $this->belongsTo(FlightInventory::class, 'flight_inventory_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(FlightInventoryAllocation::class, 'flight_inventory_id', 'flight_inventory_id')
            ->where('to_holder_type', $this->holder_type)
            ->where('to_holder_id', $this->holder_id);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeForHolder(Builder $query, string $holderType, ?int $holderId = null): void
    {
        $query->where('holder_type', $holderType);
        if ($holderId !== null) {
            $query->where('holder_id', $holderId);
        }
    }

    public function scopeHasAvailable(Builder $query): void
    {
        $query->where('available', '>', 0);
    }

    public function scopeExpired(Builder $query): void
    {
        $query->whereNotNull('expires_at')->where('expires_at', '<', now());
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getBookableAttribute(): int
    {
        return max(0, $this->available - $this->reserved);
    }

    public function getUtilizationRateAttribute(): float
    {
        if ($this->total_allocated === 0) {
            return 0.0;
        }
        return round(($this->booked / $this->total_allocated) * 100, 2);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function satisfiesInvariant(): bool
    {
        return ($this->available + $this->reserved + $this->booked + $this->frozen) === $this->total_allocated;
    }

    /** Optimistic lock: increment available, return false if constraint violated */
    public function incrementAvailable(int $seats): bool
    {
        return (bool) static::where('id', $this->id)
            ->update(['available' => \DB::raw("available + {$seats}")]);
    }

    public function decrementAvailable(int $seats): bool
    {
        return (bool) static::where('id', $this->id)
            ->where('available', '>=', $seats)
            ->update(['available' => \DB::raw("available - {$seats}")]);
    }

    // ── Filter / Sort interface ───────────────────────────────────────────────

    public function allowedFilterKeys(): array
    {
        return ['holder_type', 'holder_id', 'cabin_class', 'allocation_mode', 'is_active'];
    }

    protected function getSortableAttributes(): array
    {
        return [
            'total_allocated', 'available', 'reserved', 'booked', 'frozen',
            'holder_type', 'cabin_class', 'created_at',
        ];
    }
}
