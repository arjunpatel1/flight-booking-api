<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class FlightAirport extends Model
{
    use HasActiveStatus, HasFilters, HasSortBy;

    protected $table = 'flight_airports';

    protected $fillable = [
        'iata_code',
        'icao_code',
        'name',
        'city',
        'city_code',
        'country',
        'country_code',
        'latitude',
        'longitude',
        'timezone',
        'terminals',
        self::ACTIVE_COLUMN_NAME,
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'terminals' => 'array',
        'is_active' => 'boolean',
    ];

    public function originatingRoutes(): HasMany
    {
        return $this->hasMany(FlightRoute::class, 'origin_airport_id');
    }

    public function destinationRoutes(): HasMany
    {
        return $this->hasMany(FlightRoute::class, 'destination_airport_id');
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->where(function (Builder $q) use ($value) {
            $q->whereLike('iata_code', "%{$value}%")
                ->orWhereLike('name', "%{$value}%")
                ->orWhereLike('city', "%{$value}%")
                ->orWhereLike('country', "%{$value}%");
        });
    }

    public function allowedFilterKeys(): array
    {
        return ['search', 'city_code', 'country_code', 'is_active'];
    }

    protected function getSortableAttributes(): array
    {
        return ['iata_code', 'name', 'city', 'country', 'is_active'];
    }
}
