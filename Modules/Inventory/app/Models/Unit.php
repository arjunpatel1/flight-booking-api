<?php

namespace Modules\Inventory\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Inventory\Enums\UnitType;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property string $name
 * @property string $symbol
 * @property UnitType $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Unit extends Model
{
    use HasSortBy,
        HasFilters,
        SoftDeletes,
        HasTagsCache,
        HasCreatedBy,
        Translatable,
        HasActivityLog;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'symbol',
        'type',
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name', 'symbol'];

    /**
     * Get a list of all units.
     *
     * @return Collection
     */
    public static function list(): Collection
    {
        return Cache::tags("units")
            ->rememberForever(
                makeCacheKey(['units', 'list']),
                fn() => static::select('id', 'name', 'symbol')
                    ->latest()
                    ->get()
                    ->map(fn(Unit $unit) => [
                        'id' => $unit->id,
                        'name' => "$unit->name ($unit->symbol)"
                    ])
            );
    }


    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            "type"
        ];
    }

    /**
     * Scope a query to search across all fields.
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeSearch(Builder $query, string $value): void
    {
        $query->whereLikeTranslation('name', $value);
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
            "symbol",
            "type",
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => UnitType::class,
        ];
    }
}
