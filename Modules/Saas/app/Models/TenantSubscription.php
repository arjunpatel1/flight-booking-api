<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class TenantSubscription extends Model
{
    use BelongsToTenant,
        HasActivityLog,
        HasCreatedBy,
        HasFilters,
        HasSortBy,
        SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'subscription_plan_id',
        'status',
        'starts_at',
        'ends_at',
        'trial_ends_at',
        'cancelled_at',
        'overrides',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SaasBillingInvoice::class);
    }

    public function allowedFilterKeys(): array
    {
        return ['tenant_id', 'subscription_plan_id', 'status', 'from', 'to'];
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->whereHas('tenant', fn(Builder $query) => $query->whereLike('name', "%{$value}%"))
            ->orWhereHas('plan', fn(Builder $query) => $query->whereLike('name', "%{$value}%"));
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'overrides' => 'array',
        ];
    }
}
