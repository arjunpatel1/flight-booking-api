<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class EmployeeCompensation extends Model
{
    use HasActiveStatus,
        HasActivityLog,
        HasBranch,
        HasCreatedBy,
        HasFilters,
        HasSortBy,
        SoftDeletes;

    protected $table = 'employee_compensations';

    protected $fillable = [
        'branch_id',
        'user_id',
        'pay_type',
        'base_rate',
        'overtime_rate',
        'standard_daily_minutes',
        'effective_from',
        'effective_to',
        'is_active',
        'notes',
        'created_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withOutGlobalBranchPermission()->withoutGlobalActive()->withTrashed();
    }

    public function allowedFilterKeys(): array
    {
        return ['user_id', 'pay_type', 'is_active', 'from', 'to', self::BRANCH_COLUMN_NAME];
    }

    protected function getSortableAttributes(): array
    {
        return [
            'pay_type',
            'base_rate',
            'overtime_rate',
            'standard_daily_minutes',
            'effective_from',
            'effective_to',
            'is_active',
            self::BRANCH_COLUMN_NAME,
        ];
    }

    protected function casts(): array
    {
        return [
            'base_rate' => 'float',
            'overtime_rate' => 'float',
            'standard_daily_minutes' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
