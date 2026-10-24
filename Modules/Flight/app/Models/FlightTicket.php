<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Flight\Enums\TicketStatus;
use Modules\Saas\Models\Tenant;
use Modules\Support\Eloquent\Model;

class FlightTicket extends Model
{
    use SoftDeletes;

    protected $table = 'flight_tickets';

    protected $fillable = [
        'tenant_id',
        'flight_booking_id',
        'flight_booking_passenger_id',
        'ticket_number',
        'e_ticket_number',
        'pnr',
        'supplier_code',
        'status',
        'seat_number',
        'baggage_allowance',
        'meal_preference',
        'fare_amount',
        'tax_amount',
        'total_amount',
        'currency',
        'validating_carrier',
        'issued_at',
        'meta',
    ];

    protected $casts = [
        'status'       => TicketStatus::class,
        'fare_amount'  => 'float',
        'tax_amount'   => 'float',
        'total_amount' => 'float',
        'issued_at'    => 'datetime',
        'meta'         => 'array',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(FlightBookingPassenger::class, 'flight_booking_passenger_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isIssued(): bool
    {
        return $this->status === TicketStatus::ISSUED;
    }
}
