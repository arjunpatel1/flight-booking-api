<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Support\Eloquent\Model;

class SaasCoupon extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'type',
        'value',
        'free_months',
        'trial_extension_days',
        'plan_upgrade_id',
        'lifetime',
        'usage_limit',
        'used_count',
        'per_tenant_limit',
        'per_email_limit',
        'per_mobile_limit',
        'minimum_plan_id',
        'maximum_discount',
        'source',
        'starts_at',
        'expires_at',
        'is_active',
        'metadata',
    ];

    public function planUpgrade(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_upgrade_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(SaasCouponRedemption::class);
    }

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'free_months' => 'integer',
            'trial_extension_days' => 'integer',
            'lifetime' => 'boolean',
            'usage_limit' => 'integer',
            'used_count' => 'integer',
            'per_tenant_limit' => 'integer',
            'per_email_limit' => 'integer',
            'per_mobile_limit' => 'integer',
            'maximum_discount' => 'decimal:2',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }
}

