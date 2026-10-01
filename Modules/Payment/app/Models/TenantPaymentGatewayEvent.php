<?php

namespace Modules\Payment\Models;

use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class TenantPaymentGatewayEvent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'gateway_config_id', 'payment_session_id', 'provider_event_id',
        'event_type', 'payload_hash', 'processing_status', 'failure_code',
        'occurred_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
