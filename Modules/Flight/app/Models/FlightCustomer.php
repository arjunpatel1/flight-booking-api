<?php

namespace Modules\Flight\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class FlightCustomer extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'flight_customers';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'frequent_flyer_number',
        'membership_tier',
        'miles_balance',
        'is_active',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'miles_balance' => 'integer',
        'is_active' => 'boolean',
    ];
}
