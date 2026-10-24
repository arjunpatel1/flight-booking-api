<?php

namespace Modules\Flight\Models;

use Modules\Support\Eloquent\Model;

class FlightCabinClass extends Model
{
    protected $table = 'flight_cabin_classes';

    protected $fillable = ['code', 'name', 'sort_order', 'is_active'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
