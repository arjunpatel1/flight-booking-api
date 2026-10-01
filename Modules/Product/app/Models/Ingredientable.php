<?php

namespace Modules\Product\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Branch\Traits\HasBranch;
use Modules\Inventory\Models\Ingredient;
use Modules\Product\Database\Factories\IngredientableFactory;
use Modules\Product\Enums\IngredientOperation;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasOrder;

/**
 * @property int $id
 * @property int|null $ingredient_id
 * @property-read Ingredient $ingredient
 * @property int $ingredientable_id
 * @property string $ingredientable_type
 * @property float $quantity
 * @property IngredientOperation $operation
 * @property int $priority
 * @property float $loss_pct
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Ingredientable extends Model
{
    use HasCreatedBy,
        HasOrder,
        HasFactory,
        HasBranch;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'id',
        'ingredient_id',
        'ingredientable_id',
        'ingredientable_type',
        'quantity',
        'priority',
        'loss_pct',
        'note',
        'operation',
        self::BRANCH_COLUMN_NAME,
        self::ORDER_COLUMN_NAME,
    ];

    protected static function newFactory(): IngredientableFactory
    {
        return IngredientableFactory::new();
    }

    /**
     * Get ingredientable
     *
     * @return MorphTo
     */
    public function ingredientable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get ingredient
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation' => IngredientOperation::class,
        ];
    }
}
