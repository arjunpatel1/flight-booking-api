<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class EmployeeAttendance extends Model
{
    use SoftDeletes,
        HasActivityLog,
        HasBranch,
        HasCreatedBy,
        HasFilters,
        HasSortBy;

    public static string $defaultDateColumn = 'clock_in_at';

    protected $fillable = [
        'branch_id',
        'user_id',
        'employee_shift_id',
        'clock_in_at',
        'clock_out_at',
        'break_minutes',
        'status',
        'notes',
        'meta',
        'created_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withOutGlobalBranchPermission()->withoutGlobalActive()->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(EmployeeShift::class, 'employee_shift_id')->withoutGlobalActive()->withTrashed();
    }

    public function workedMinutes(): Attribute
    {
        return Attribute::get(function () {
            $clockOut = $this->clock_out_at ?: now();

            return max(0, (int) floor($this->clock_in_at?->diffInMinutes($clockOut) ?? 0) - (int) $this->break_minutes);
        });
    }

    public function allowedFilterKeys(): array
    {
        return [
            'user_id',
            'employee_shift_id',
            'status',
            'from',
            'to',
            self::BRANCH_COLUMN_NAME,
        ];
    }

    protected function getSortableAttributes(): array
    {
        return [
            'clock_in_at',
            'clock_out_at',
            'status',
            'break_minutes',
            self::BRANCH_COLUMN_NAME,
        ];
    }

    protected function casts(): array
    {
        return [
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'break_minutes' => 'integer',
            'meta' => 'array',
        ];
    }
}
