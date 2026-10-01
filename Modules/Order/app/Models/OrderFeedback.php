<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\User\Models\User;

class OrderFeedback extends Model
{
    use HasActivityLog, HasBranch, HasFilters, HasSortBy;

    protected $table = 'order_feedback';

    protected $fillable = [
        'order_id',
        'branch_id',
        'customer_id',
        'rating',
        'tags',
        'comment',
        'source',
        'submitted_at',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function allowedFilterKeys(): array
    {
        return [
            'search',
            'branch_id',
            'rating',
            'source',
            'from',
            'to',
        ];
    }

    protected function casts(): array
    {
        return [
            'rating' => 'int',
            'tags' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    protected function getSortableAttributes(): array
    {
        return [
            'rating',
            'source',
            'submitted_at',
            'created_at',
        ];
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->where(function (Builder $query) use ($value) {
            $query
                ->whereLike('comment', "%{$value}%")
                ->orWhereLike('source', "%{$value}%")
                ->orWhereHas('order', function (Builder $query) use ($value) {
                    $query
                        ->whereLike('reference_no', "%{$value}%")
                        ->orWhereLike('order_number', "%{$value}%");
                })
                ->orWhereHas('customer', function (Builder $query) use ($value) {
                    $query
                        ->whereLike('name', "%{$value}%")
                        ->orWhereLike('phone', "%{$value}%");
                });
        });
    }
}
