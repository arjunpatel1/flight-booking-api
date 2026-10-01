<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Money;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\User\Models\User;

class Expense extends Model
{
    use HasFactory,
        SoftDeletes,
        HasBranch,
        HasFilters,
        HasSortBy;

    public static string $defaultDateColumn = 'expense_date';

    protected $fillable = [
        'branch_id',
        'expense_category_id',
        'user_id',
        'amount',
        'currency',
        'expense_date',
        'description',
        'reference',
        'receipt_path',
        'is_recurring',
        'recurring_frequency',
        'status',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'is_recurring' => 'boolean',
        'approved_at' => 'datetime',
        'amount' => 'decimal:4',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withOutGlobalBranchPermission();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    public function amount(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::get(
            fn($amount) => new Money($amount, $this->currency)
        );
    }

    public function scopeSearch($query, string $value): void
    {
        $query->where('description', 'like', "%{$value}%")
            ->orWhere('reference', 'like', "%{$value}%");
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('expense_date', [$startDate, $endDate]);
    }

    protected function getSortableAttributes(): array
    {
        return [
            'expense_date',
            'amount',
            'status',
            'expense_category_id',
        ];
    }
}
