<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class ReportSchedule extends Model
{
    use HasBranch;

    protected $fillable = [
        'branch_id',
        'user_id',
        'report_key',
        'name',
        'frequency',
        'run_at',
        'day_of_week',
        'day_of_month',
        'formats',
        'recipients',
        'filters',
        'is_active',
        'last_run_at',
        'next_run_at',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withOutGlobalBranchPermission();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'formats' => 'array',
            'recipients' => 'array',
            'filters' => 'array',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }
}
