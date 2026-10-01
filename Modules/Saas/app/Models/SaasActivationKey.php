<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Support\Eloquent\Model;

class SaasActivationKey extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'key',
        'name',
        'subscription_plan_id',
        'tenant_id',
        'partner',
        'activation_limit',
        'used_count',
        'offline_allowed',
        'status',
        'expires_at',
        'revoked_at',
        'metadata',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SaasActivationEvent::class);
    }

    protected function casts(): array
    {
        return [
            'activation_limit' => 'integer',
            'used_count' => 'integer',
            'offline_allowed' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}

