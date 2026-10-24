<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class FlightInventory extends Model
{
    use BelongsToTenant, HasFilters, HasSortBy;

    protected $table = 'flight_inventories';

    protected $fillable = [
        'tenant_id',
        'inventory_id',
        'airline_id',
        'flight_number',
        'origin_airport_id',
        'destination_airport_id',
        'departure_time',
        'arrival_time',
        'operating_days',
        'aircraft_id',
        'cabin_class',
        'total_seats',
        'available_seats',
        'baggage_policy',
        'seat_map_configuration',
        'is_refundable',
        'cancellation_policy',
        'reschedule_policy',
        'status',
        'base_price',
    ];

    protected $casts = [
        'operating_days' => 'array',
        'baggage_policy' => 'array',
        'seat_map_configuration' => 'array',
        'total_seats' => 'integer',
        'available_seats' => 'integer',
        'is_refundable' => 'boolean',
        'base_price' => 'decimal:4',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(FlightAirline::class, 'airline_id');
    }

    public function originAirport(): BelongsTo
    {
        return $this->belongsTo(FlightAirport::class, 'origin_airport_id');
    }

    public function destinationAirport(): BelongsTo
    {
        return $this->belongsTo(FlightAirport::class, 'destination_airport_id');
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(FlightAircraft::class, 'aircraft_id');
    }

    public function fares(): HasMany
    {
        return $this->hasMany(FlightFare::class, 'flight_inventory_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function scopeAvailable(Builder $query): void
    {
        $query->where('status', 'active')->where('available_seats', '>', 0);
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->where(function (Builder $q) use ($value) {
            $q->whereLike('inventory_id', "%{$value}%")
                ->orWhereLike('flight_number', "%{$value}%");
        });
    }

    public function allowedFilterKeys(): array
    {
        return [
            'search',
            'airline_id',
            'flight_number',
            'origin_airport_id',
            'destination_airport_id',
            'cabin_class',
            'status',
            'is_refundable',
        ];
    }

    protected function getSortableAttributes(): array
    {
        return [
            'inventory_id',
            'flight_number',
            'departure_time',
            'arrival_time',
            'cabin_class',
            'total_seats',
            'available_seats',
            'base_price',
            'status',
            'created_at',
        ];
    }
}
