<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Flight\Enums\FlightWebhookStatus;
use Modules\Saas\Models\Tenant;
use Modules\Support\Eloquent\Model;

class FlightWebhookEvent extends Model
{
    protected $table = 'flight_webhook_events';

    protected $fillable = [
        'tenant_id',
        'provider',
        'event_id',
        'event_type',
        'signature_valid',
        'payload',
        'status',
        'received_at',
        'processed_at',
        'failure_reason',
        'metadata',
    ];

    protected $casts = [
        'signature_valid' => 'boolean',
        'payload'         => 'array',
        'status'          => FlightWebhookStatus::class,
        'received_at'     => 'datetime',
        'processed_at'    => 'datetime',
        'metadata'        => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        if (empty($tenantId)) {
            return $query;
        }

        return $query->where('tenant_id', $tenantId);
    }
}
