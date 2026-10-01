<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;

class FactKitchenDaily extends Model
{
    use HasBranch, HasFactory;

    protected $fillable = [
        'branch_id',
        'business_date',
        'kitchen_station_id',
        'currency',
        'total_orders',
        'completed_orders',
        'delayed_orders',
        'avg_preparation_time_minutes',
        'avg_kot_delay_minutes',
        'max_preparation_time_minutes',
        'orders_per_hour',
        'on_time_percentage',
        'max_concurrent_items',
        'avg_concurrent_items',
        'metadata',
        'calculated_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'avg_preparation_time_minutes' => 'decimal:2',
        'avg_kot_delay_minutes' => 'decimal:2',
        'max_preparation_time_minutes' => 'integer',
        'orders_per_hour' => 'decimal:2',
        'on_time_percentage' => 'decimal:2',
        'max_concurrent_items' => 'integer',
        'avg_concurrent_items' => 'decimal:2',
        'metadata' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(\Modules\Pos\Models\KitchenStation::class, 'kitchen_station_id');
    }

    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('business_date', [$startDate, $endDate]);
    }

    public function scopeForStation($query, $stationId)
    {
        return $query->where('kitchen_station_id', $stationId);
    }

    public function scopeForPeriod($query, $period, $date = null)
    {
        $date = $date ?? now();
        
        return match($period) {
            'today' => $query->where('business_date', $date->toDateString()),
            'yesterday' => $query->where('business_date', $date->subDay()->toDateString()),
            'week' => $query->whereBetween('business_date', [
                $date->startOfWeek()->toDateString(),
                $date->endOfWeek()->toDateString()
            ]),
            'month' => $query->whereBetween('business_date', [
                $date->startOfMonth()->toDateString(),
                $date->endOfMonth()->toDateString()
            ]),
            'year' => $query->whereBetween('business_date', [
                $date->startOfYear()->toDateString(),
                $date->endOfYear()->toDateString()
            ]),
            default => $query,
        };
    }
}
