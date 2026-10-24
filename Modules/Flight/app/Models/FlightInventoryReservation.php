<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Flight\Enums\HolderType;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

/**
 * Phase 4: Inventory Reservation
 *
 * Short-lived seat hold during the booking pipeline.
 * Seats are de-ducted from balance.available into balance.reserved.
 * Must be confirmed (→ booked) or released (→ available) before expires_at.
 */
class FlightInventoryReservation extends Model
{
    use BelongsToTenant;

    protected $table = 'flight_inventory_reservations';

    protected $fillable = [
        'reservation_ref',
        'tenant_id',
        'flight_inventory_id',
        'cabin_class',
        'holder_type',
        'holder_id',
        'seats_reserved',
        'status',
        'session_token',
        'booking_reference',
        'reserved_by',
        'expires_at',
        'confirmed_at',
        'released_at',
    ];

    protected $casts = [
        'seats_reserved' => 'integer',
        'expires_at'     => 'datetime',
        'confirmed_at'   => 'datetime',
        'released_at'    => 'datetime',
        'holder_type'    => HolderType::class,
    ];

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->reservation_ref)) {
                $model->reservation_ref = (string) \Str::uuid();
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

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'ACTIVE');
    }

    public function scopeExpired(Builder $query): void
    {
        $query->where('status', 'ACTIVE')
            ->where('expires_at', '<', now());
    }

    public function scopeBySession(Builder $query, string $token): void
    {
        $query->where('session_token', $token);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE' && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->status === 'ACTIVE' && $this->expires_at->isPast();
    }
}
