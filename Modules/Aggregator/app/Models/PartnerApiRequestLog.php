<?php

namespace Modules\Aggregator\Models;

use Modules\Support\Eloquent\Model;

class PartnerApiRequestLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'request_id', 'partner_id', 'credential_id', 'tenant_id', 'branch_id',
        'method', 'path', 'idempotency_hash', 'external_order_id',
        'response_status', 'ip_address', 'user_agent', 'duration_ms', 'created_at',
    ];
}
