<?php

namespace Modules\SeatingPlan\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\SeatingPlan\Database\Factories\FloorFactory;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasOrder;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property string $name
 * @property int $layout_width
 * @property int $layout_height
 * @property bool $show_grid
 * @property bool $show_guide_lines
 * @property bool $show_zone_labels
 * @property bool $show_table_labels
 * @property bool $compact_tables
 * @property array|null $zone_layouts
 * @property array|null $layout_elements
 * @property array|null $planner_snapshot
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Floor extends Model
{
    use SoftDeletes,
        HasTagsCache,
        HasCreatedBy,
        HasActiveStatus,
        Translatable,
        HasSortBy,
        HasBranch,
        HasFilters,
        HasOrder,
        HasFactory,
        HasActivityLog;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'layout_width',
        'layout_height',
        'show_grid',
        'show_guide_lines',
        'show_zone_labels',
        'show_table_labels',
        'compact_tables',
        'zone_layouts',
        'layout_elements',
        'planner_snapshot',
        self::ACTIVE_COLUMN_NAME,
        self::BRANCH_COLUMN_NAME,
        self::ORDER_COLUMN_NAME
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name'];

    /**
     * Get a list of all floors.
     *
     * @param int|null $branchId
     * @return Collection
     */
    public static function list(?int $branchId = null): Collection
    {
        return Cache::tags("floors")
            ->rememberForever(
                makeCacheKey([
                    'floors',
                    is_null($branchId) ? 'all' : "branch-{$branchId}",
                    'list'
                ]),
                fn() => static::select(
                    'id',
                    'name',
                    'layout_width',
                    'layout_height',
                    'show_grid',
                    'show_guide_lines',
                    'show_zone_labels',
                    'show_table_labels',
                    'compact_tables',
                    'zone_layouts',
                    'layout_elements',
                    'planner_snapshot',
                )
                    ->when(!is_null($branchId), fn($query) => $query->whereBranch($branchId))
                    ->get()
                    ->map(fn(Floor $floor) => [
                        'id' => $floor->id,
                        'name' => $floor->name,
                        'layout_width' => $floor->layout_width,
                        'layout_height' => $floor->layout_height,
                        'show_grid' => $floor->show_grid,
                        'show_guide_lines' => $floor->show_guide_lines,
                        'show_zone_labels' => $floor->show_zone_labels,
                        'show_table_labels' => $floor->show_table_labels,
                        'compact_tables' => $floor->compact_tables,
                        'zone_layouts' => $floor->zone_layouts ?: [],
                        'layout_elements' => $floor->layout_elements ?: [],
                        'planner_snapshot' => $floor->planner_snapshot ?: null,
                    ])
            );
    }

    protected static function newFactory(): FloorFactory
    {
        return FloorFactory::new();
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            self::ACTIVE_COLUMN_NAME,
            self::BRANCH_COLUMN_NAME
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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            self::ACTIVE_COLUMN_NAME => 'boolean',
            self::ORDER_COLUMN_NAME => "int",
            'layout_width' => 'int',
            'layout_height' => 'int',
            'show_grid' => 'boolean',
            'show_guide_lines' => 'boolean',
            'show_zone_labels' => 'boolean',
            'show_table_labels' => 'boolean',
            'compact_tables' => 'boolean',
            'zone_layouts' => 'array',
            'layout_elements' => 'array',
            'planner_snapshot' => 'array',
        ];
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
            self::ACTIVE_COLUMN_NAME,
            self::BRANCH_COLUMN_NAME,
        ];
    }
}
