<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Flight\Enums\PassengerType;
use Modules\Saas\Models\Tenant;
use Modules\Support\Eloquent\Model;

class FlightBookingPassenger extends Model
{
    protected $table = 'flight_booking_passengers';

    protected $fillable = [
        'booking_id',
        'tenant_id',
        'passenger_type',
        'title',
        'first_name',
        'last_name',
        'date_of_birth',
        'gender',
        'nationality',
        'is_international',
        'passport_number',
        'passport_expiry',
        'passport_country',
        'seat_preference',
        'meal_preference',
        'frequent_flyer_number',
        'contact_email',
        'contact_phone',
        'meta',
    ];

    protected $casts = [
        'passenger_type'   => PassengerType::class,
        'date_of_birth'    => 'date',
        'passport_expiry'  => 'date',
        'is_international' => 'boolean',
        'meta'             => 'array',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'booking_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function ancillaries(): HasMany
    {
        return $this->hasMany(FlightBookingAncillary::class, 'passenger_id');
    }

    public function ticket(): HasOne
    {
        return $this->hasOne(FlightTicket::class, 'flight_booking_passenger_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->title} {$this->first_name} {$this->last_name}");
    }
}
