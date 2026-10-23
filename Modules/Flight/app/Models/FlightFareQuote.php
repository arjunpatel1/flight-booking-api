<?php

namespace Modules\Flight\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Saas\Models\Tenant;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class FlightFareQuote extends Model
{
    protected $table = 'flight_fare_quotes';

    protected $fillable = [
        'quote_id',
        'hold_id',
        'hold_reference',
        'tenant_id',
        'user_id',
        'supplier_code',
        'flight_inventory_id',
        'flight_fare_id',
        'currency',
        'selected_itinerary',
        'fare_details',
        'pricing_breakdown',
        'customer_price',
        'search_context',
        'expires_at',
        'status',
        'idempotency_key',
    ];

    protected $casts = [
        'selected_itinerary' => 'array',
        'fare_details'       => 'array',
        'pricing_breakdown'  => 'array',
        'search_context'     => 'array',
        'customer_price'     => 'decimal:2',
        'expires_at'         => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->quote_id)) {
                $model->quote_id = 'QUOTE-' . strtoupper(Str::random(10));
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

    public function hold(): BelongsTo
    {
        return $this->belongsTo(FlightHold::class, 'hold_id');
    }

    public function flightInventory(): BelongsTo
    {
        return $this->belongsTo(FlightInventory::class, 'flight_inventory_id');
    }

    public function flightFare(): BelongsTo
    {
        return $this->belongsTo(FlightFare::class, 'flight_fare_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'ACTIVE')
              ->where('expires_at', '>', Carbon::now());
    }

    public function scopeForTenant(Builder $query, ?int $tenantId): void
    {
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isValid(): bool
    {
        return $this->status === 'ACTIVE' && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->status === 'EXPIRED' || ($this->status === 'ACTIVE' && $this->expires_at->isPast());
    }

    public function getRemainingSecondsAttribute(): int
    {
        if (! $this->isValid()) {
            return 0;
        }

        return max(0, Carbon::now()->diffInSeconds($this->expires_at, false));
    }
}
