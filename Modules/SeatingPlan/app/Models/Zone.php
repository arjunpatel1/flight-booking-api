<?php

namespace Modules\SeatingPlan\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Pricing\Models\PriceType;
use Modules\SeatingPlan\Database\Factories\ZoneFactory;
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
 * @property int $floor_id
 * @property-read  Floor $floor
 * @property string|null $color
 * @property int|null $price_type_id
 * @property-read PriceType|null $priceType
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Zone extends Model
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
        'floor_id',
        'color',
        'price_type_id',
        self::ACTIVE_COLUMN_NAME,
        self::BRANCH_COLUMN_NAME
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name'];

    /**
     * Get a list of all zones.
     *
     * @param int|null $branchId
     * @param int|null $floorId
     * @return Collection
     */
    public static function list(?int $branchId = null, ?int $floorId = null): Collection
    {
        return Cache::tags("zones")
            ->rememberForever(
                makeCacheKey([
                    'zones',
                    is_null($branchId) ? 'all' : "branch-{$branchId}",
                    is_null($floorId) ? 'all' : "floor-{$floorId}",
                    'list'
                ]),
                fn() => static::select('id', 'name', 'floor_id')
                    ->when(!is_null($branchId), fn($query) => $query->whereBranch($branchId))
                    ->when(!is_null($floorId), fn($query) => $query->where("floor_id", $floorId))
                    ->get()
                    ->map(fn(Zone $zone) => [
                        'id' => $zone->id,
                        'name' => $zone->name,
                        'floor_id' => $zone->floor_id,
                    ])
            );
    }

    protected static function newFactory(): ZoneFactory
    {
        return ZoneFactory::new();
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            "floor_id",
            "price_type_id",
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
     * Get floor
     *
     * @return BelongsTo
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class)
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
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
            'price_type_id' => 'int',
        ];
    }

    /**
     * Get price type
     *
     * @return BelongsTo
     */
    public function priceType(): BelongsTo
    {
        return $this->belongsTo(PriceType::class);
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
            "floor_id",
            self::ACTIVE_COLUMN_NAME,
            self::BRANCH_COLUMN_NAME,
        ];
    }
}
