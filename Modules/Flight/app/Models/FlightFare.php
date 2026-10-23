<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class FlightFare extends Model
{
    use BelongsToTenant, HasFilters, HasSortBy;

    protected $table = 'flight_fares';

    protected $fillable = [
        'tenant_id',
        'flight_inventory_id',
        'fare_family_id',
        'fare_family_code',
        'fare_basis_code',
        'cabin_class',
        'currency',
        'supplier_code',
        'supplier_fare',
        'pricing_rules_adjustment',
        'base_fare',
        'taxes',
        'supplier_fees',
        'platform_markup',
        'tenant_markup',
        'client_markup',
        'convenience_fee',
        'gst',
        'discount',
        'final_customer_price',
        'baggage',
        'is_refundable',
        'is_changeable',
        'total_seats',
        'available_seats',
        'valid_from',
        'valid_until',
        'status',
        'metadata',
    ];

    protected $casts = [
        'baggage' => 'array',
        'metadata' => 'array',
        'is_refundable' => 'boolean',
        'is_changeable' => 'boolean',
        'supplier_fare' => 'decimal:4',
        'pricing_rules_adjustment' => 'decimal:4',
        'base_fare' => 'decimal:4',
        'taxes' => 'decimal:4',
        'supplier_fees' => 'decimal:4',
        'platform_markup' => 'decimal:4',
        'tenant_markup' => 'decimal:4',
        'client_markup' => 'decimal:4',
        'convenience_fee' => 'decimal:4',
        'gst' => 'decimal:4',
        'discount' => 'decimal:4',
        'final_customer_price' => 'decimal:4',
        'total_seats' => 'integer',
        'available_seats' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(FlightInventory::class, 'flight_inventory_id');
    }

    public function flightInventory(): BelongsTo
    {
        return $this->belongsTo(FlightInventory::class, 'flight_inventory_id');
    }

    public function fareFamily(): BelongsTo
    {
        return $this->belongsTo(FlightFareFamily::class, 'fare_family_id');
    }

    public function fareRule(): HasOne
    {
        return $this->hasOne(FlightFareRule::class, 'flight_fare_id');
    }

    public function fareRules(): HasMany
    {
        return $this->hasMany(FlightFareRule::class, 'flight_fare_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where('available_seats', '>', 0);
    }

    public function getFareCodeAttribute(): ?string
    {
        return $this->attributes['fare_basis_code'] ?? ($this->attributes['fare_code'] ?? null);
    }

    public function getFinalPriceAttribute(): ?float
    {
        return isset($this->attributes['final_customer_price'])
            ? (float) $this->attributes['final_customer_price']
            : (isset($this->attributes['final_price']) ? (float) $this->attributes['final_price'] : null);
    }
}
