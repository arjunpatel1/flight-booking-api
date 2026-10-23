<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class FlightSubscriptionUsageLog extends Model
{
    use BelongsToTenant;

    protected $table = 'flight_subscription_usage_logs';

    protected $fillable = [
        'tenant_id',
        'metric',
        'units',
        'current_used',
        'quota_limit',
        'is_overage',
        'reference',
        'actor',
    ];

    protected $casts = [
        'units'        => 'integer',
        'current_used' => 'integer',
        'quota_limit'  => 'integer',
        'is_overage'   => 'boolean',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
