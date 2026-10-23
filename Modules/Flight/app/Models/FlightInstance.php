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

class FlightInstance extends Model
{
    use BelongsToTenant, HasFilters, HasSortBy;

    protected $table = 'flight_instances';

    protected $fillable = [
        'tenant_id',
        'schedule_id',
        'aircraft_id',
        'flight_number',
        'departure_datetime',
        'arrival_datetime',
        'status',
        'terminal_departure',
        'terminal_arrival',
        'gate',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'schedule_id' => 'integer',
        'aircraft_id' => 'integer',
        'departure_datetime' => 'datetime',
        'arrival_datetime' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(FlightSchedule::class, 'schedule_id');
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(FlightAircraft::class, 'aircraft_id');
    }

    public function fares(): HasMany
    {
        return $this->hasMany(FlightFare::class, 'flight_instance_id');
    }

    public function allowedFilterKeys(): array
    {
        return ['schedule_id', 'flight_number', 'status', 'departure_date'];
    }

    protected function getSortableAttributes(): array
    {
        return ['departure_datetime', 'arrival_datetime', 'status', 'created_at'];
    }
}
