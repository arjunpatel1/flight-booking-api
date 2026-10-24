<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Flight\Enums\AmendmentStatus;
use Modules\Flight\Enums\AmendmentType;
use Modules\Saas\Models\Tenant;
use Modules\Support\Eloquent\Model;

class FlightBookingAmendment extends Model
{
    use SoftDeletes;

    protected $table = 'flight_booking_amendments';

    protected $fillable = [
        'tenant_id',
        'flight_booking_id',
        'amendment_reference',
        'amendment_type',
        'previous_flight_inventory_id',
        'new_flight_inventory_id',
        'previous_itinerary',
        'new_itinerary',
        'previous_fare',
        'new_fare',
        'fare_difference',
        'reschedule_fee',
        'total_additional_charge',
        'currency',
        'status',
        'previous_pnr',
        'new_pnr',
        'reason',
        'audit_trail',
        'completed_at',
    ];

    protected $casts = [
        'previous_itinerary'      => 'array',
        'new_itinerary'           => 'array',
        'previous_fare'           => 'decimal:2',
        'new_fare'                => 'decimal:2',
        'fare_difference'         => 'decimal:2',
        'reschedule_fee'          => 'decimal:2',
        'total_additional_charge' => 'decimal:2',
        'amendment_type'          => AmendmentType::class,
        'status'                  => AmendmentStatus::class,
        'audit_trail'             => 'array',
        'completed_at'            => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->amendment_reference)) {
                $model->amendment_reference = 'AMD-' . strtoupper(Str::random(10));
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function previousInventory(): BelongsTo
    {
        return $this->belongsTo(FlightInventory::class, 'previous_flight_inventory_id');
    }

    public function newInventory(): BelongsTo
    {
        return $this->belongsTo(FlightInventory::class, 'new_flight_inventory_id');
    }
}
