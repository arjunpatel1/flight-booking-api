<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;

class FactInventoryDaily extends Model
{
    use HasBranch, HasFactory;

    protected $fillable = [
        'branch_id',
        'business_date',
        'ingredient_id',
        'warehouse_id',
        'currency',
        'opening_stock',
        'purchased_qty',
        'consumed_qty',
        'wasted_qty',
        'transferred_in_qty',
        'transferred_out_qty',
        'closing_stock',
        'opening_value',
        'purchase_value',
        'consumed_value',
        'wasted_value',
        'closing_value',
        'unit',
        'metadata',
        'calculated_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'opening_stock' => 'decimal:4',
        'purchased_qty' => 'decimal:4',
        'consumed_qty' => 'decimal:4',
        'wasted_qty' => 'decimal:4',
        'transferred_in_qty' => 'decimal:4',
        'transferred_out_qty' => 'decimal:4',
        'closing_stock' => 'decimal:4',
        'opening_value' => 'decimal:4',
        'purchase_value' => 'decimal:4',
        'consumed_value' => 'decimal:4',
        'wasted_value' => 'decimal:4',
        'closing_value' => 'decimal:4',
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

    public function scopeForIngredient($query, $ingredientId)
    {
        return $query->where('ingredient_id', $ingredientId);
    }

    public function scopeForWarehouse($query, $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeLowStock($query, $threshold = 10)
    {
        return $query->where('closing_stock', '<=', $threshold);
    }

    public function scopeHighWastage($query, $thresholdPercentage = 5)
    {
        return $query->whereRaw('(wasted_qty / NULLIF(purchased_qty + opening_stock, 0)) * 100 >= ?', [$thresholdPercentage]);
    }
}
