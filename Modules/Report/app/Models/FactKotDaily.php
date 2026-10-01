<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;

class FactKotDaily extends Model
{
    use HasBranch, HasFactory;

    protected $fillable = [
        'branch_id',
        'business_date',
        'hour',
        'kitchen_station_id',
        'product_id',
        'currency',
        'total_kots',
        'on_time_kots',
        'delayed_kots',
        'avg_kot_time_minutes',
        'avg_delay_minutes',
        'total_quantity',
        'metadata',
        'calculated_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'hour' => 'integer',
        'avg_kot_time_minutes' => 'decimal:2',
        'avg_delay_minutes' => 'decimal:2',
        'total_quantity' => 'integer',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\Product::class, 'product_id');
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

    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeForHour($query, $hour)
    {
        return $query->where('hour', $hour);
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
