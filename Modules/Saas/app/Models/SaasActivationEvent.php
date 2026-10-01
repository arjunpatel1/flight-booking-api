<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Eloquent\Model;

class SaasActivationEvent extends Model
{
    protected $fillable = [
        'saas_activation_key_id',
        'tenant_id',
        'type',
        'status',
        'device_id',
        'ip_address',
        'message',
        'payload',
        'processed_at',
    ];

    public function activationKey(): BelongsTo
    {
        return $this->belongsTo(SaasActivationKey::class, 'saas_activation_key_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}

