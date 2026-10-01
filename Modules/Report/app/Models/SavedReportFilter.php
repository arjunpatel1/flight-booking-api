<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class SavedReportFilter extends Model
{
    use HasBranch;

    protected $fillable = [
        'branch_id',
        'user_id',
        'report_key',
        'name',
        'filters',
        'is_default',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withOutGlobalBranchPermission();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_default' => 'boolean',
        ];
    }
}
