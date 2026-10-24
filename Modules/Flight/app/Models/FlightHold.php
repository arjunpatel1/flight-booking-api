<?php

namespace Modules\Flight\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Flight\Enums\HoldStatus;
use Modules\Saas\Models\Tenant;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class FlightHold extends Model
{
    use SoftDeletes;

    protected $table = 'flight_inventory_holds';

    protected $fillable = [
        'hold_id',
        'tenant_id',
        'user_id',
        'flight_inventory_id',
        'flight_fare_id',
        'supplier_code',
        'cabin_class',
        'quantity',
        'status',
        'expires_at',
        'created_by',
        'reference',
        'idempotency_key',
        'reservation_id',
        'meta',
    ];

    protected $casts = [
        'status'     => HoldStatus::class,
        'quantity'   => 'integer',
        'expires_at' => 'datetime',
        'meta'       => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->hold_id)) {
                $model->hold_id = 'HOLD-' . strtoupper(Str::random(10));
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function flightInventory(): BelongsTo
    {
        return $this->belongsTo(FlightInventory::class, 'flight_inventory_id');
    }

    public function flightFare(): BelongsTo
    {
        return $this->belongsTo(FlightFare::class, 'flight_fare_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(FlightInventoryReservation::class, 'reservation_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(FlightFareQuote::class, 'hold_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): void
    {
        $query->where('status', HoldStatus::ACTIVE)
              ->where('expires_at', '>', Carbon::now());
    }

    public function scopeExpired(Builder $query): void
    {
        $query->where('status', HoldStatus::ACTIVE)
              ->where('expires_at', '<=', Carbon::now());
    }

    public function scopeForTenant(Builder $query, ?int $tenantId): void
    {
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === HoldStatus::ACTIVE && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->status === HoldStatus::EXPIRED || ($this->status === HoldStatus::ACTIVE && $this->expires_at->isPast());
    }

    public function getRemainingSecondsAttribute(): int
    {
        if (! $this->isActive()) {
            return 0;
        }

        return max(0, Carbon::now()->diffInSeconds($this->expires_at, false));
    }
}
