<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class FactShiftDaily extends Model
{
    use HasBranch, HasFactory;

    protected $fillable = [
        'branch_id',
        'business_date',
        'shift_id',
        'user_id',
        'pos_session_id',
        'currency',
        'shift_start_time',
        'shift_end_time',
        'shift_duration_minutes',
        'break_minutes',
        'total_orders',
        'completed_orders',
        'cancelled_orders',
        'gross_sales',
        'net_sales',
        'average_order_value',
        'opening_float',
        'declared_cash',
        'system_cash_sales',
        'cash_over_short',
        'cash_total',
        'card_total',
        'upi_total',
        'other_total',
        'orders_per_hour',
        'sales_per_hour',
        'metadata',
        'calculated_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'shift_start_time' => 'datetime',
        'shift_end_time' => 'datetime',
        'gross_sales' => 'decimal:4',
        'net_sales' => 'decimal:4',
        'average_order_value' => 'decimal:4',
        'opening_float' => 'decimal:4',
        'declared_cash' => 'decimal:4',
        'system_cash_sales' => 'decimal:4',
        'cash_over_short' => 'decimal:4',
        'cash_total' => 'decimal:4',
        'card_total' => 'decimal:4',
        'upi_total' => 'decimal:4',
        'other_total' => 'decimal:4',
        'orders_per_hour' => 'decimal:2',
        'sales_per_hour' => 'decimal:4',
        'metadata' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(\Modules\User\Models\EmployeeShift::class, 'shift_id');
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(\Modules\Pos\Models\PosSession::class, 'pos_session_id');
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
