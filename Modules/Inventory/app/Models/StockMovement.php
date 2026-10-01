<?php

namespace Modules\Inventory\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Inventory\Database\Factories\StockMovementFactory;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property int $ingredient_id
 * @property-read Ingredient $ingredient
 * @property StockMovementType $type
 * @property int|null $source_id
 * @property string|null $source_type
 * @property float $quantity
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class StockMovement extends Model
{
    use HasFactory,
        HasSortBy,
        HasFilters,
        SoftDeletes,
        HasTagsCache,
        HasCreatedBy,
        HasBranch,
        Translatable,
        HasActivityLog;

    public static string $defaultDateColumn = 'stock_movements.created_at';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "ingredient_id",
        "type",
        "quantity",
        "note",
        "source_id",
        "source_type",
        self::BRANCH_COLUMN_NAME,
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name', 'symbol'];

    protected static function newFactory(): StockMovementFactory
    {
        return StockMovementFactory::new();
    }

    /**
     * Boot the model and handle stock adjustments on create, update, and delete.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::created(function (StockMovement $movement) {
            $movement->applyStockChange();
        });

        static::updated(function (StockMovement $movement) {
            $movement->recalculateStockFromHistory();
        });

        static::deleted(function (StockMovement $movement) {
            $movement->recalculateStockFromHistory();
        });
    }

    /**
     * Adjust the current_stock of the related ingredient based on movement type.
     *
     * @return void
     */
    public function applyStockChange(): void
    {
        $ingredient = $this->ingredient;

        if (!$ingredient) return;

        match ($this->type) {
            StockMovementType::In,
            StockMovementType::AdjustAdd,
            StockMovementType::TransferIn => $ingredient->increment('current_stock', $this->quantity),
            StockMovementType::Out,
            StockMovementType::Spoil,
            StockMovementType::AdjustSubtract,
            StockMovementType::TransferOut,
            StockMovementType::ReturnToSupplier,
            StockMovementType::Sample,
            StockMovementType::Waste => $ingredient->decrement('current_stock', $this->quantity),
        };
    }

    /**
     * Recalculate the current stock of the ingredient from all historical movements.
     *
     * @return void
     */
    public function recalculateStockFromHistory(): void
    {
        $ingredient = $this->ingredient;

        if (!$ingredient) return;

        $stock = self::where('ingredient_id', $this->ingredient_id)
            ->selectRaw("
            SUM(CASE
                WHEN type IN ('in', 'adjust_add', 'transfer_in') THEN quantity
                WHEN type IN ('out', 'spoil', 'adjust_subtract', 'transfer_out', 'return_supplier', 'sample', 'waste') THEN -quantity
                ELSE 0
            END) as total
        ")
            ->value('total');

        $ingredient->update(['current_stock' => $stock ?? 0]);
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            "ingredient_id",
            "type",
            "group_by_date",
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
        $query->whereHas("ingredient", function (Builder $query) use ($value) {
            $query->whereLikeTranslation('name', $value);
        });
    }

    /**
     * Get an ingredient model
     *
     * @return BelongsTo
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)
            ->withOutGlobalBranchPermission()
            ->withTrashed();
    }

    /**
     * Get source name
     *
     * @return string|null
     */
    public function getSourceName(): ?string
    {
        if (is_null($this->source_type)) return null;
        $explode = explode('\\', $this->source_type);
        $source = str(last($explode))->snake()->plural();
        $module = str($explode[1])->snake();
        return __("$module::$source.$source");
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "branch_id",
            "ingredient_id",
            "type",
            "quantity",
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
            'type' => StockMovementType::class,
            "start_date" => "datetime",
            "end_date" => "datetime",
        ];
    }
}
