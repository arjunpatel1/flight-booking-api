<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;

class FactSalesDaily extends Model
{
    use HasBranch, HasFactory;

    protected $fillable = [
        'branch_id',
        'business_date',
        'currency',
        'total_orders',
        'dine_in_orders',
        'takeaway_orders',
        'delivery_orders',
        'cancelled_orders',
        'refunded_orders',
        'gross_sales',
        'net_sales',
        'discount_total',
        'tax_total',
        'refund_total',
        'profit_total',
        'average_order_value',
        'unique_customers',
        'new_customers',
        'returning_customers',
        'cash_total',
        'card_total',
        'upi_total',
        'wallet_total',
        'aggregator_gross_sales',
        'aggregator_commission',
        'aggregator_payout',
        'payment_breakdown',
        'order_type_breakdown',
        'metadata',
        'calculated_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'gross_sales' => 'decimal:4',
        'net_sales' => 'decimal:4',
        'discount_total' => 'decimal:4',
        'tax_total' => 'decimal:4',
        'refund_total' => 'decimal:4',
        'average_order_value' => 'decimal:4',
        'cash_total' => 'decimal:4',
        'card_total' => 'decimal:4',
        'upi_total' => 'decimal:4',
        'wallet_total' => 'decimal:4',
        'aggregator_gross_sales' => 'decimal:4',
        'aggregator_commission' => 'decimal:4',
        'aggregator_payout' => 'decimal:4',
        'payment_breakdown' => 'array',
        'order_type_breakdown' => 'array',
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

    public function scopeForPeriod($query, $period, $date = null)
    {
        $date = $date ?? now();

        return match ($period) {
            'today' => $query->where('business_date', $date->toDateString()),
            'yesterday' => $query->where('business_date', $date->subDay()->toDateString()),
            'week' => $query->whereBetween('business_date', [
                $date->startOfWeek()->toDateString(),
                $date->endOfWeek()->toDateString(),
            ]),
            'month' => $query->whereBetween('business_date', [
                $date->startOfMonth()->toDateString(),
                $date->endOfMonth()->toDateString(),
            ]),
            'year' => $query->whereBetween('business_date', [
                $date->startOfYear()->toDateString(),
                $date->endOfYear()->toDateString(),
            ]),
            default => $query,
        };
    }
}
