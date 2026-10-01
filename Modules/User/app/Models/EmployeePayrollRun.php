<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;

class EmployeePayrollRun extends Model
{
    use HasActivityLog, HasBranch, HasCreatedBy;

    protected $fillable = [
        'branch_id',
        'period_from',
        'period_to',
        'status',
        'gross_amount',
        'deduction_amount',
        'net_amount',
        'approved_by',
        'approved_at',
        'paid_by',
        'paid_at',
        'payment_method',
        'payment_reference',
        'voided_by',
        'voided_at',
        'void_reason',
        'notes',
        'created_by',
    ];

    public function payslips(): HasMany
    {
        return $this->hasMany(EmployeePayslip::class, 'employee_payroll_run_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withOutGlobalBranchPermission()->withoutGlobalActive()->withTrashed();
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by')->withOutGlobalBranchPermission()->withoutGlobalActive()->withTrashed();
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by')->withOutGlobalBranchPermission()->withoutGlobalActive()->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'gross_amount' => 'float',
            'deduction_amount' => 'float',
            'net_amount' => 'float',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
