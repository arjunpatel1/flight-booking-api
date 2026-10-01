<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\User\Models\User;

class WaiterDailyCollection extends Model
{
    use HasBranch, HasFilters;

    public static string $defaultDateColumn = 'business_date';

    protected $fillable = [
        'branch_id',
        'waiter_id',
        'business_date',
        'currency',
        'orders_served',
        'tables_served',
        'sales_total',
        'collection_total',
        'cash_total',
        'upi_total',
        'card_total',
        'tips_total',
        'pending_total',
        'average_bill_value',
        'payment_breakdown',
        'metadata',
        'calculated_at',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withOutGlobalBranchPermission();
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id')
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    public function allowedFilterKeys(): array
    {
        return [
            'branch_id',
            'waiter_id',
            'from',
            'to',
            'group_by_date',
        ];
    }

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'payment_breakdown' => 'array',
            'metadata' => 'array',
            'calculated_at' => 'datetime',
        ];
    }
}
