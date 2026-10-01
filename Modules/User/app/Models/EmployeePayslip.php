<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;

class EmployeePayslip extends Model
{
    use HasBranch;

    protected $fillable = [
        'branch_id',
        'employee_payroll_run_id',
        'user_id',
        'worked_minutes',
        'regular_minutes',
        'overtime_minutes',
        'base_amount',
        'overtime_amount',
        'gross_amount',
        'deduction_amount',
        'net_amount',
        'meta',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(EmployeePayrollRun::class, 'employee_payroll_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withOutGlobalBranchPermission()->withoutGlobalActive()->withTrashed();
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(EmployeePayslipDeduction::class, 'employee_payslip_id');
    }

    protected function casts(): array
    {
        return [
            'worked_minutes' => 'integer',
            'regular_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'base_amount' => 'float',
            'overtime_amount' => 'float',
            'gross_amount' => 'float',
            'deduction_amount' => 'float',
            'net_amount' => 'float',
            'meta' => 'array',
        ];
    }
}
