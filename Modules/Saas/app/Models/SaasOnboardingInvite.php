<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class SaasOnboardingInvite extends Model
{
    protected $fillable = [
        'uuid', 'tenant_id', 'email', 'phone', 'token_hash', 'payload',
        'status', 'expires_at', 'completed_at', 'created_by',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->status === 'pending' && $this->expires_at?->isFuture();
    }
}
