<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class ReportExport extends Model
{
    use HasBranch;

    protected $fillable = [
        'report_job_id',
        'branch_id',
        'requested_by',
        'report_key',
        'format',
        'status',
        'disk',
        'file_path',
        'file_name',
        'file_size',
        'filters',
        'error_message',
        'expires_at',
        'completed_at',
    ];

    public function reportJob(): BelongsTo
    {
        return $this->belongsTo(ReportJob::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withOutGlobalBranchPermission();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
