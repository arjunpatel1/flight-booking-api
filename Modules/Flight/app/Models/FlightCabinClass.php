<?php

namespace Modules\Flight\Models;

use Modules\Flight\Models\Concerns\BelongsToTenantCatalog;
use Modules\Support\Eloquent\Model;

class FlightCabinClass extends Model
{
    use BelongsToTenantCatalog;

    protected $table = 'flight_cabin_classes';

    protected $fillable = ['tenant_id', 'code', 'name', 'sort_order', 'is_active'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
