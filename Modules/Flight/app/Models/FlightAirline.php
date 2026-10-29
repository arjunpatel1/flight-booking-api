<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Flight\Models\Concerns\BelongsToTenantCatalog;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class FlightAirline extends Model
{
    use BelongsToTenantCatalog, HasActiveStatus, HasFilters, HasSortBy;

    protected $table = 'flight_airlines';

    protected $fillable = [
        'tenant_id',
        'iata_code',
        'icao_code',
        'name',
        'country',
        'country_code',
        'logo_url',
        'alliance',
        self::ACTIVE_COLUMN_NAME,
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function schedules(): HasMany
    {
        return $this->hasMany(FlightSchedule::class, 'airline_id');
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->where(function (Builder $q) use ($value) {
            $q->whereLike('iata_code', "%{$value}%")
                ->orWhereLike('name', "%{$value}%");
        });
    }

    public function allowedFilterKeys(): array
    {
        return ['search', 'alliance', 'is_active'];
    }

    protected function getSortableAttributes(): array
    {
        return ['iata_code', 'name', 'alliance', 'is_active'];
    }
}
