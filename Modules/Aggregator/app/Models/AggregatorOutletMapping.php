<?php

namespace Modules\Aggregator\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class AggregatorOutletMapping extends Model
{
    use HasActivityLog,
        HasBranch,
        HasCreatedBy,
        HasFilters,
        HasSortBy,
        SoftDeletes;

    protected $fillable = [
        'aggregator_integration_id',
        'branch_id',
        'external_outlet_id',
        'external_outlet_name',
        'meta',
        'is_active',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(AggregatorIntegration::class, 'aggregator_integration_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function allowedFilterKeys(): array
    {
        return [
            'aggregator_integration_id',
            'branch_id',
            'is_active',
        ];
    }

    protected function getSortableAttributes(): array
    {
        return [
            'aggregator_integration_id',
            'branch_id',
            'external_outlet_id',
            'is_active',
            'created_at',
        ];
    }

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
