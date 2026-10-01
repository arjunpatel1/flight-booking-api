<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Eloquent\Model;

class EmployeePayslipDeduction extends Model
{
    protected $fillable = [
        'employee_payslip_id',
        'type',
        'label',
        'amount',
        'notes',
    ];

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(EmployeePayslip::class, 'employee_payslip_id');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'float',
        ];
    }
}
