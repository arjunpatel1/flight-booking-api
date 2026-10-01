<?php

namespace Modules\Aggregator\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Aggregator\Enums\AggregatorProvider;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class AggregatorIntegration extends Model
{
    use HasActivityLog,
        HasCreatedBy,
        HasFilters,
        HasSortBy,
        SoftDeletes;

    protected $fillable = [
        'provider',
        'name',
        'base_url',
        'credentials',
        'webhook_secret',
        'settings',
        'is_active',
    ];

    public function outletMappings(): HasMany
    {
        return $this->hasMany(AggregatorOutletMapping::class);
    }

    public function menuMappings(): HasMany
    {
        return $this->hasMany(AggregatorMenuMapping::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(AggregatorSyncLog::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(AggregatorWebhookEvent::class);
    }

    public function allowedFilterKeys(): array
    {
        return [
            'search',
            'provider',
            'is_active',
            'from',
            'to',
        ];
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->whereLike('name', "%{$value}%")
            ->orWhereLike('provider', "%{$value}%");
    }

    protected function getSortableAttributes(): array
    {
        return [
            'provider',
            'name',
            'is_active',
            'created_at',
            'updated_at',
        ];
    }

    protected function casts(): array
    {
        return [
            'provider' => AggregatorProvider::class,
            'credentials' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
