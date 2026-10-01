<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;

class FactExpenseDaily extends Model
{
    use HasBranch, HasFactory;

    protected $fillable = [
        'branch_id',
        'business_date',
        'expense_category_id',
        'currency',
        'total_expenses',
        'approved_expenses',
        'pending_expenses',
        'rejected_expenses',
        'total_transactions',
        'approved_transactions',
        'vs_previous_day',
        'vs_previous_week',
        'vs_previous_month',
        'metadata',
        'calculated_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'total_expenses' => 'decimal:4',
        'approved_expenses' => 'decimal:4',
        'pending_expenses' => 'decimal:4',
        'rejected_expenses' => 'decimal:4',
        'vs_previous_day' => 'decimal:4',
        'vs_previous_week' => 'decimal:4',
        'vs_previous_month' => 'decimal:4',
        'metadata' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(\Modules\Expense\Models\ExpenseCategory::class, 'expense_category_id');
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
