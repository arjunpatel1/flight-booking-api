<?php

namespace Modules\Aggregator\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Aggregator\Enums\AggregatorSyncStatus;
use Modules\Aggregator\Enums\AggregatorSyncType;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class AggregatorSyncLog extends Model
{
    use HasCreatedBy,
        HasFilters,
        HasSortBy;

    protected $fillable = [
        'aggregator_integration_id',
        'type',
        'status',
        'reference',
        'message',
        'request_payload',
        'response_payload',
        'error_message',
        'attempts',
        'max_attempts',
        'next_retry_at',
        'started_at',
        'finished_at',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(AggregatorIntegration::class, 'aggregator_integration_id');
    }

    public function allowedFilterKeys(): array
    {
        return [
            'aggregator_integration_id',
            'type',
            'status',
            'reference',
            'from',
            'to',
        ];
    }

    protected function getSortableAttributes(): array
    {
        return [
            'type',
            'status',
            'reference',
            'attempts',
            'created_at',
            'started_at',
            'finished_at',
        ];
    }

    protected function casts(): array
    {
        return [
            'type' => AggregatorSyncType::class,
            'status' => AggregatorSyncStatus::class,
            'request_payload' => 'array',
            'response_payload' => 'array',
            'next_retry_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
