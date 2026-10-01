<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class MonthlySalesReport extends Model
{
    use HasBranch;

    protected $fillable = [
        'branch_id',
        'generated_by',
        'month',
        'year',
        'filters',
        'status',
        'progress',
        'total_orders',
        'total_revenue',
        'currency',
        'disk',
        'file_path',
        'file_name',
        'error_message',
        'generated_at',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withOutGlobalBranchPermission();
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by')
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
