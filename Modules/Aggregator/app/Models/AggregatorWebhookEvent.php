<?php

namespace Modules\Aggregator\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Aggregator\Enums\AggregatorSyncStatus;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class AggregatorWebhookEvent extends Model
{
    use HasFilters,
        HasSortBy;

    protected $fillable = [
        'aggregator_integration_id',
        'event_type',
        'external_event_id',
        'status',
        'signature',
        'source_ip',
        'headers',
        'payload',
        'error_message',
        'processed_at',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(AggregatorIntegration::class, 'aggregator_integration_id');
    }

    public function allowedFilterKeys(): array
    {
        return [
            'aggregator_integration_id',
            'event_type',
            'external_event_id',
            'status',
            'from',
            'to',
        ];
    }

    protected function getSortableAttributes(): array
    {
        return [
            'event_type',
            'external_event_id',
            'status',
            'created_at',
            'processed_at',
        ];
    }

    protected function casts(): array
    {
        return [
            'status' => AggregatorSyncStatus::class,
            'headers' => 'array',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
