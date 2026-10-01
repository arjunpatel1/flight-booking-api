<?php

namespace Modules\Aggregator\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Order\Models\Order;
use Modules\Support\Eloquent\Model;

class PartnerApiOrderMapping extends Model
{
    protected $fillable = [
        'uuid', 'partner_id', 'tenant_id', 'credential_id', 'branch_id',
        'order_id', 'external_order_id', 'status',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
