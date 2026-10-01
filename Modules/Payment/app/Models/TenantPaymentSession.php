<?php

namespace Modules\Payment\Models;

use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class TenantPaymentSession extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'branch_id', 'order_id', 'gateway_config_id', 'reference',
        'provider', 'provider_payment_id', 'idempotency_key', 'amount', 'currency',
        'status', 'intent_url', 'qr_data', 'transaction_reference', 'expires_at',
        'finalized_at', 'payment_id', 'created_by',
    ];

    protected $hidden = ['idempotency_key'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'intent_url' => 'encrypted',
            'qr_data' => 'encrypted',
            'expires_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }
}
