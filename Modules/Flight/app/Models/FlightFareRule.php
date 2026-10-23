<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class FlightFareRule extends Model
{
    use BelongsToTenant, HasFilters, HasSortBy;

    protected $table = 'flight_fare_rules';

    protected $fillable = [
        'tenant_id',
        'flight_fare_id',
        'cancellation_policy',
        'refund_policy',
        'reschedule_policy',
        'baggage_policy',
        'advance_purchase_days',
        'min_stay_days',
        'max_stay_days',
        'rule_details',
        'is_active',
    ];

    protected $casts = [
        'rule_details' => 'array',
        'advance_purchase_days' => 'integer',
        'min_stay_days' => 'integer',
        'max_stay_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function fare(): BelongsTo
    {
        return $this->belongsTo(FlightFare::class, 'flight_fare_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
