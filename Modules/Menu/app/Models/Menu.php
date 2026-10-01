<?php

namespace Modules\Menu\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Menu\Database\Factories\MenuFactory;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Support\Traits\HasUuid;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Menu extends Model
{
    use HasActiveStatus,
        HasActivityLog,
        HasFactory,
        HasCreatedBy,
        HasBranch,
        HasTagsCache,
        HasSortBy,
        HasFilters,
        HasUuid,
        Translatable,
        SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'description',
        'order_types',
        self::ACTIVE_COLUMN_NAME,
        self::BRANCH_COLUMN_NAME
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name', 'description'];

    /**
     * Get a list of all menus.
     *
     * @param bool $withoutGlobalActive
     * @param int|null $branchId
     * @return Collection
     */
    public static function list(?int $branchId = null, bool $withoutGlobalActive = false, ?string $orderType = null): Collection
    {
        return Cache::tags("menus")
            ->rememberForever(
                makeCacheKey([
                    'menus',
                    is_null($branchId) ? 'all' : "branch-{$branchId}",
                    $withoutGlobalActive ? 'all' : 'active',
                    is_null($orderType) ? 'all-order-types' : "order-type-$orderType",
                    'list'
                ]),
                fn() => static::select('id', 'name', 'order_types')
                    ->when(!is_null($branchId), fn($query) => $query->whereBranch($branchId))
                    ->when($withoutGlobalActive, fn($query) => $query->withoutGlobalActive())
                    ->when(!is_null($orderType), fn(Builder $query) => $query->where(function (Builder $query) use ($orderType) {
                        $query->whereNull('order_types')
                            ->orWhereJsonLength('order_types', 0)
                            ->orWhereJsonContains('order_types', $orderType);
                    }))
                    ->orderBy("is_active", "desc")
                    ->get()
                    ->map(fn(Menu $menu) => [
                        'id' => $menu->id,
                        'name' => $menu->name,
                        'order_types' => $menu->order_types ?: [],
                    ])
            );
    }

    /**
     * Get active menu
     *
     * @param int $branchId
     * @param bool $withBranch
     * @return Menu|null
     */
    public static function getActiveMenu(int $branchId, bool $withBranch = false): ?Menu
    {
        return static::where('branch_id', $branchId)
            ->when($withBranch, fn(Builder $query) => $query->with(["branch"]))
            ->where('is_active', true)
            ->withOutGlobalBranchPermission()
            ->latest()
            ->first();
    }

    protected static function newFactory(): MenuFactory
    {
        return MenuFactory::new();
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            self::BRANCH_COLUMN_NAME,
            self::ACTIVE_COLUMN_NAME,
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
            self::BRANCH_COLUMN_NAME,
            self::ACTIVE_COLUMN_NAME,
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
            self::ACTIVE_COLUMN_NAME => "boolean",
            'order_types' => 'array',
        ];
    }

}
