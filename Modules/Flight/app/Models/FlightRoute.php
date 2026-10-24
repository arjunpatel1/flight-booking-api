<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class FlightRoute extends Model
{
    use BelongsToTenant, HasActiveStatus, HasFilters, HasSortBy;

    protected $table = 'flight_routes';

    protected $fillable = [
        'tenant_id',
        'origin_airport_id',
        'destination_airport_id',
        'distance_km',
        'flight_duration_minutes',
        'flight_number',
        'flight_date',
        'flight_time',
        'end_date',
        'end_time',
        'purchase_date',
        'pnr_number',
        'departure_terminal',
        'arrival_terminal',
        'adult_count',
        'child_count',
        'total_seats',
        'available_seats',
        'consumed_seats',
        'total_adult_seats',
        'total_child_seats',
        'available_adult_seats',
        'available_child_seats',
        'consumed_adult_seats',
        'consumed_child_seats',
        'total_seats_price',
        'adult_price',
        'child_price',
        'infant_price',
        'gst_percentage',
        'gst_amount',
        'total_adult_price_with_gst',
        'total_child_price_with_gst',
        'total_infant_price_with_gst',
        'fare_families',
        'fare_family_prices',
        'fare_family_pax_prices',
        'cabin_fares',
        'stops',
        'stops_type',
        'stops_types',
        self::ACTIVE_COLUMN_NAME,
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'origin_airport_id' => 'integer',
        'destination_airport_id' => 'integer',
        'distance_km' => 'integer',
        'flight_duration_minutes' => 'integer',
        'flight_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'purchase_date' => 'date:Y-m-d',
        'adult_count' => 'integer',
        'child_count' => 'integer',
        'total_seats' => 'integer',
        'available_seats' => 'integer',
        'consumed_seats' => 'integer',
        'total_adult_seats' => 'integer',
        'total_child_seats' => 'integer',
        'available_adult_seats' => 'integer',
        'available_child_seats' => 'integer',
        'consumed_adult_seats' => 'integer',
        'consumed_child_seats' => 'integer',
        'total_seats_price' => 'decimal:2',
        'adult_price' => 'decimal:2',
        'child_price' => 'decimal:2',
        'infant_price' => 'decimal:2',
        'gst_percentage' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'total_adult_price_with_gst' => 'decimal:2',
        'total_child_price_with_gst' => 'decimal:2',
        'total_infant_price_with_gst' => 'decimal:2',
        'fare_families' => 'array',
        'fare_family_prices' => 'array',
        'fare_family_pax_prices' => 'array',
        'cabin_fares' => 'array',
        'stops' => 'integer',
        'stops_types' => 'array',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function originAirport(): BelongsTo
    {
        return $this->belongsTo(FlightAirport::class, 'origin_airport_id');
    }

    public function destinationAirport(): BelongsTo
    {
        return $this->belongsTo(FlightAirport::class, 'destination_airport_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(FlightSchedule::class, 'route_id');
    }

    public function allowedFilterKeys(): array
    {
        return ['origin_airport_id', 'destination_airport_id', 'is_active'];
    }

    protected function getSortableAttributes(): array
    {
        return ['distance_km', 'flight_duration_minutes', 'is_active', 'created_at'];
    }
}
