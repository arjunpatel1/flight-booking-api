<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class FlightSubscriptionOverage extends Model
{
    use BelongsToTenant;

    protected $table = 'flight_subscription_overages';

    protected $fillable = [
        'tenant_id',
        'metric',
        'units',
        'unit_price',
        'total_price',
        'reference',
        'actor',
        'description',
        'metadata',
    ];

    protected $casts = [
        'units'       => 'integer',
        'unit_price'  => 'float',
        'total_price' => 'float',
        'metadata'    => 'array',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
