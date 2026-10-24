<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Flight\Enums\AncillaryCategory;
use Modules\Saas\Models\Tenant;
use Modules\Support\Eloquent\Model;

class FlightBookingAncillary extends Model
{
    protected $table = 'flight_booking_ancillaries';

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'passenger_id',
        'ancillary_id',
        'category',
        'code',
        'name',
        'description',
        'price',
        'currency',
        'quantity',
        'seat_number',
        'supplier_code',
        'status',
        'meta',
    ];

    protected $casts = [
        'category' => AncillaryCategory::class,
        'price'    => 'decimal:2',
        'quantity' => 'integer',
        'meta'     => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'booking_id');
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(FlightBookingPassenger::class, 'passenger_id');
    }

    public function ancillary(): BelongsTo
    {
        return $this->belongsTo(FlightAncillary::class, 'ancillary_id');
    }

    public function getTotalPriceAttribute(): float
    {
        return (float) ($this->price * $this->quantity);
    }
}
