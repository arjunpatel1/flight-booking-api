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

class FlightSchedule extends Model
{
    use BelongsToTenant, HasActiveStatus, HasFilters, HasSortBy;

    protected $table = 'flight_schedules';

    protected $fillable = [
        'tenant_id',
        'route_id',
        'airline_id',
        'flight_number',
        'departure_time',
        'arrival_time',
        'day_offset',
        'days_of_week',
        'valid_from',
        'valid_until',
        self::ACTIVE_COLUMN_NAME,
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'route_id' => 'integer',
        'airline_id' => 'integer',
        'day_offset' => 'integer',
        'days_of_week' => 'array',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(FlightRoute::class, 'route_id');
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(FlightAirline::class, 'airline_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(FlightInstance::class, 'schedule_id');
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->whereLike('flight_number', "%{$value}%");
    }

    public function allowedFilterKeys(): array
    {
        return ['search', 'route_id', 'airline_id', 'flight_number', 'is_active'];
    }

    protected function getSortableAttributes(): array
    {
        return ['flight_number', 'departure_time', 'arrival_time', 'is_active', 'created_at'];
    }
}
