<?php

namespace Modules\Flight\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class FlightCustomer extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'flight_customers';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'phone_verified_at',
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
        'phone_verified_at' => 'datetime',
    ];

    // Customers are not staff. The tenant/branch global scopes (BelongsToTenant,
    // HasBranch) ask the signed-in user these questions; answering "no tenant"
    // makes them behave exactly as they do for a guest on a public endpoint.
    // Customer endpoints restrict bookings by ownership instead.
    public function isSuperAdmin(): bool
    {
        return false;
    }

    public function assignedToTenant(): bool
    {
        return false;
    }

    public function assignedToBranch(): bool
    {
        return false;
    }

    public function tenantId(): ?int
    {
        return null;
    }

    public static function newFrequentFlyerNumber(): string
    {
        do {
            $number = 'CRT-' . strtoupper(Str::random(8));
        } while (static::query()->where('frequent_flyer_number', $number)->exists());

        return $number;
    }
}
