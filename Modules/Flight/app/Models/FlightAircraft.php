<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class FlightAircraft extends Model
{
    use HasActiveStatus, HasFilters, HasSortBy;

    protected $table = 'flight_aircraft';

    protected $fillable = [
        'iata_code',
        'aircraft_type',
        'model',
        'registration',
        'manufacturer',
        'total_seats',
        'seat_configuration',
        self::ACTIVE_COLUMN_NAME,
    ];

    protected $casts = [
        'total_seats' => 'integer',
        'seat_configuration' => 'array',
        'is_active' => 'boolean',
    ];

    public function instances(): HasMany
    {
        return $this->hasMany(FlightInstance::class, 'aircraft_id');
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->where(function (Builder $q) use ($value) {
            $q->whereLike('iata_code', "%{$value}%")
                ->orWhereLike('model', "%{$value}%")
                ->orWhereLike('manufacturer', "%{$value}%");
        });
    }

    public function allowedFilterKeys(): array
    {
        return ['search', 'manufacturer', 'is_active'];
    }

    protected function getSortableAttributes(): array
    {
        return ['iata_code', 'model', 'manufacturer', 'total_seats', 'is_active'];
    }
}
