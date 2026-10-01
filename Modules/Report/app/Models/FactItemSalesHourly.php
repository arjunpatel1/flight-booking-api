<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;

class FactItemSalesHourly extends Model
{
    use HasBranch, HasFactory;

    protected $fillable = [
        'branch_id',
        'business_date',
        'hour',
        'product_id',
        'category_id',
        'currency',
        'quantity_sold',
        'gross_sales',
        'net_sales',
        'discount_total',
        'cost_total',
        'profit_total',
        'margin_percent',
        'order_count',
        'metadata',
        'calculated_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'hour' => 'integer',
        'gross_sales' => 'decimal:4',
        'net_sales' => 'decimal:4',
        'discount_total' => 'decimal:4',
        'cost_total' => 'decimal:4',
        'profit_total' => 'decimal:4',
        'margin_percent' => 'decimal:2',
        'metadata' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('business_date', [$startDate, $endDate]);
    }

    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeForCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeForHour($query, $hour)
    {
        return $query->where('hour', $hour);
    }

    public function scopeForPeakHours($query, $limit = 5)
    {
        return $query->selectRaw('hour, SUM(quantity_sold) as total_quantity, SUM(net_sales) as total_sales')
            ->groupBy('hour')
            ->orderByDesc('total_quantity')
            ->limit($limit);
    }
}
