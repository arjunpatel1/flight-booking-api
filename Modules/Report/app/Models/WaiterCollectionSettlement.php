<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class WaiterCollectionSettlement extends Model
{
    use HasBranch;

    protected $fillable = [
        'branch_id',
        'waiter_id',
        'settled_by',
        'business_date',
        'currency',
        'expected_amount',
        'settled_amount',
        'difference_amount',
        'status',
        'notes',
        'settled_at',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withOutGlobalBranchPermission();
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id')
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by')
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'settled_at' => 'datetime',
        ];
    }
}
