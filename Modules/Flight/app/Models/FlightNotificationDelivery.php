<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Flight\Enums\FlightNotificationChannel;
use Modules\Flight\Enums\FlightNotificationEvent;
use Modules\Flight\Enums\FlightNotificationStatus;
use Modules\Saas\Models\Tenant;
use Modules\Support\Eloquent\Model;

class FlightNotificationDelivery extends Model
{
    protected $table = 'flight_notification_deliveries';

    protected $fillable = [
        'booking_id',
        'tenant_id',
        'event',
        'channel',
        'recipient',
        'template',
        'status',
        'provider',
        'provider_reference',
        'attempt_count',
        'sent_at',
        'delivered_at',
        'failed_at',
        'failure_reason',
        'metadata',
        'idempotency_key',
    ];

    protected $casts = [
        'event'          => FlightNotificationEvent::class,
        'channel'        => FlightNotificationChannel::class,
        'status'         => FlightNotificationStatus::class,
        'attempt_count'  => 'integer',
        'sent_at'        => 'datetime',
        'delivered_at'   => 'datetime',
        'failed_at'      => 'datetime',
        'metadata'       => 'array',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'booking_id');
    }

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
