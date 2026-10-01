<?php

namespace Modules\Aggregator\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Menu\Models\Menu;
use Modules\Menu\Traits\HasMenu;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class AggregatorMenuMapping extends Model
{
    use HasActivityLog,
        HasCreatedBy,
        HasFilters,
        HasMenu,
        HasSortBy,
        SoftDeletes;

    protected $fillable = [
        'aggregator_integration_id',
        'menu_id',
        'external_menu_id',
        'meta',
        'sync_enabled',
        'last_synced_at',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(AggregatorIntegration::class, 'aggregator_integration_id');
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function allowedFilterKeys(): array
    {
        return [
            'aggregator_integration_id',
            'menu_id',
            'sync_enabled',
        ];
    }

    protected function getSortableAttributes(): array
    {
        return [
            'aggregator_integration_id',
            'menu_id',
            'external_menu_id',
            'sync_enabled',
            'last_synced_at',
            'created_at',
        ];
    }

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'sync_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}
