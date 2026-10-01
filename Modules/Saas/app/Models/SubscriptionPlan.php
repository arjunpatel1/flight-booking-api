<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class SubscriptionPlan extends Model
{
    use HasActivityLog,
        HasActiveStatus,
        HasCreatedBy,
        HasFilters,
        HasSortBy,
        SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'billing_cycle',
        'price',
        'currency',
        'access_scope',
        'features',
        'limits',
        self::ACTIVE_COLUMN_NAME,
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    public function allowedFilterKeys(): array
    {
        return ['search', 'billing_cycle', 'access_scope', 'is_active', 'from', 'to'];
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->whereLike('name', "%{$value}%")
            ->orWhereLike('code', "%{$value}%");
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'features' => 'array',
            'limits' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
