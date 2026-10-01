<?php

namespace Modules\Option\Models;

use Arr;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Option\Database\Factories\OptionFactory;
use Modules\Option\Enums\OptionType;
use Modules\Product\Services\ProductIngredient\ProductIngredientService;
use Modules\Product\Services\ProductIngredient\ProductIngredientServiceInterface;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasOrder;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Support\Traits\HasUuid;
use Modules\Translation\Traits\Translatable;
use Throwable;

/**
 * @property int $id
 * @property string $name
 * @property OptionType $type
 * @property bool $is_required
 * @property bool $is_global
 * @property Collection $values
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * @method static Builder globals()
 */
class Option extends Model
{
    use SoftDeletes,
        HasCreatedBy,
        Translatable,
        HasSortBy,
        HasTagsCache,
        HasOrder,
        HasBranch,
        HasFilters,
        HasUuid,
        HasFactory,
        HasActivityLog;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'id',
        'name',
        'type',
        'is_required',
        'is_global',
        self::BRANCH_COLUMN_NAME,
        self::ORDER_COLUMN_NAME,
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name'];

    /**
     * Get a list of all options global.
     *
     * @param int|null $branchId
     * @return Collection
     */
    public static function listGlobal(?int $branchId = null): Collection
    {
        return Cache::tags("options")
            ->rememberForever(
                makeCacheKey([
                    'options',
                    is_null($branchId) ? 'all' : "branch-$branchId",
                    'global',
                    'list'
                ]),
                fn() => static::select('id', 'name')
                    ->when(!is_null($branchId), fn($query) => $query->whereBranch($branchId))
                    ->globals()
                    ->get()
                    ->map(fn(Option $option) => [
                        'id' => $option->id,
                        'name' => $option->name,
                    ])
            );
    }

    protected static function newFactory(): OptionFactory
    {
        return OptionFactory::new();
    }

    /**
     * Scope a query to only include global options.
     *
     * @param Builder $query
     * @return void
     */
    public function scopeGlobals(Builder $query): void
    {
        $query->where('is_global', true);
    }

    /**
     * Save values for the option.
     *
     * @param array $values
     *
     * @return void
     * @throws Throwable
     */
    public function saveValues(array $values = []): void
    {
        /** @var ProductIngredientService $productIngredientService */
        $productIngredientService = app(ProductIngredientServiceInterface::class);

        $ids = $this->getDeleteCandidates($values);

        if ($ids->isNotEmpty()) {
            $this->values()->whereIn('id', $ids)->delete();
        }

        $counter = 0;


        foreach (array_reset_index($values) as $attributes) {
            $ingredients = $attributes['ingredients'] ?? null;
            $attributes = Arr::except($attributes, ['ingredients']);

            $attributes += [
                'order' => ++$counter,
                "branch_id" => $this->branch_id
            ];

            /** @var OptionValue $value */
            $value = $this->values()->updateOrCreate([
                'id' => Arr::get($attributes, 'id'),
                "branch_id" => $this->branch_id
            ], $attributes);

            if (!is_null($ingredients)) {
                $productIngredientService->syncForOptionValue($value->id, $this->branch_id, $ingredients);
            }
        }

    }

    /**
     * Get delete candidates
     *
     * @param array $values
     * @return Collection
     */
    private function getDeleteCandidates(array $values): Collection
    {
        return $this->values()
            ->pluck('id')
            ->diff(Arr::pluck($values, 'id'));
    }

    /**
     * Get the values for the option.
     *
     * @return mixed
     */
    public function values(): HasMany
    {
        return $this->hasMany(OptionValue::class)
            ->with(["branch:id,currency"])
            ->orderBy('order');
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "type",
            "from",
            "to",
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
            'is_global' => 'boolean',
            'is_required' => 'boolean',
            "type" => OptionType::class,
        ];
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
            "type",
            self::BRANCH_COLUMN_NAME,
        ];
    }
}
