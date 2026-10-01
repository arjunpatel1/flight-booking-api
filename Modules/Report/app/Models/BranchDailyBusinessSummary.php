<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;

class BranchDailyBusinessSummary extends Model
{
    use HasBranch;

    protected $fillable = [
        'branch_id',
        'business_date',
        'currency',
        'total_orders',
        'completed_paid_orders',
        'pending_orders',
        'cancelled_orders',
        'refunded_orders',
        'gross_sales',
        'net_sales',
        'payment_ledger_total',
        'reconciliation_difference',
        'discount_total',
        'tax_total',
        'expense_total',
        'refund_total',
        'profit_total',
        'cash_in_hand',
        'pending_collections',
        'payment_breakdown',
        'metadata',
        'calculated_at',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withOutGlobalBranchPermission();
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
