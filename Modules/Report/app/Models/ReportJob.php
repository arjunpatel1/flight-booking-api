<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class ReportJob extends Model
{
    use HasBranch;

    protected $fillable = [
        'branch_id',
        'requested_by',
        'report_key',
        'status',
        'progress',
        'filters',
        'payload',
        'error_message',
        'queued_at',
        'started_at',
        'completed_at',
    ];

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

    public function exports(): HasMany
    {
        return $this->hasMany(ReportExport::class);
    }

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'payload' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
