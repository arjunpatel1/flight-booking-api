<?php

namespace Modules\Inventory\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Branch\Traits\HasBranchCurrency;
use Modules\Inventory\Database\Factories\IngredientFactory;
use Modules\Support\Eloquent\Model;
use Modules\Support\Money;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property string $name
 * @property int $unit_id
 * @property Unit $unit
 * @property float $cost_per_unit
 * @property float $alert_quantity
 * @property float $current_stock
 * @property float $is_returnable
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Ingredient extends Model
{
    use HasFactory,
        HasSortBy,
        HasFilters,
        SoftDeletes,
        HasTagsCache,
        HasCreatedBy,
        HasBranch,
        HasBranchCurrency,
        Translatable,
        HasActivityLog;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "name",
        "unit_id",
        "cost_per_unit",
        "alert_quantity",
        "current_stock",
        "is_returnable",
        self::BRANCH_COLUMN_NAME
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name'];

    /**
     * The relations to eager load on every query.
     *
     * @var array
     */
    protected $with = ["unit"];

    /**
     * Get a list of all ingredients.
     *
     * @param int|null $branchId
     * @return Collection
     */
    public static function list(?int $branchId = null): Collection
    {
        return Cache::tags("ingredients")
            ->rememberForever(
                makeCacheKey([
                    'ingredients',
                    is_null($branchId) ? 'all' : "branch-{$branchId}",
                    'list'
                ]),
                fn() => static::select('id', 'name', 'unit_id')
                    ->when(!is_null($branchId), fn($query) => $query->whereBranch($branchId))
                    ->get()
                    ->map(fn(Ingredient $ingredient) => [
                        'id' => $ingredient->id,
                        'name' => $ingredient->name,
                        "symbol" => ucfirst($ingredient->unit->symbol),
                    ])
            );
    }

    protected static function newFactory(): IngredientFactory
    {
        return IngredientFactory::new();
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            "unit_id",
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
     * Get a unit model
     *
     * @return BelongsTo
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)
            ->withTrashed();
    }

    /**
     * Get cost per unit
     *
     * @return Attribute
     */
    public function costPerUnit(): Attribute
    {
        return Attribute::get(fn($costPerUnit) => new Money($costPerUnit, $this->currency));
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
            "cost_per_unit",
            "alert_quantity",
            "current_stock"
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
            "alert_quantity" => "float",
            "current_stock" => "float",
            "is_returnable" => "boolean",
        ];
    }
}
