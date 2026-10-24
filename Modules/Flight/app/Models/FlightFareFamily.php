<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class FlightFareFamily extends Model
{
    use BelongsToTenant, HasFilters, HasSortBy;

    protected $table = 'flight_fare_families';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'cabin_class',
        'description',
        'features',
        'baggage_allowance',
        'changeability',
        'refundability',
        'is_active',
    ];

    protected $casts = [
        'features' => 'array',
        'baggage_allowance' => 'array',
        'changeability' => 'array',
        'refundability' => 'array',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function fares(): HasMany
    {
        return $this->hasMany(FlightFare::class, 'fare_family_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
