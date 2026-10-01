<?php

namespace Modules\Aggregator\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Order\Models\Order;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;

class AggregatorOrderMapping extends Model
{
    use HasCreatedBy;

    protected $fillable = [
        'aggregator_integration_id',
        'order_id',
        'external_order_id',
        'external_order_number',
        'external_status',
        'payload',
        'synced_at',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(AggregatorIntegration::class, 'aggregator_integration_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
