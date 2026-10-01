<?php

namespace Modules\Option\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Branch\Traits\HasBranchCurrency;
use Modules\Option\Database\Factories\OptionValueFactory;
use Modules\Product\Models\Ingredientable;
use Modules\Product\Models\Product;
use Modules\Support\Eloquent\Model;
use Modules\Support\Enums\PriceType;
use Modules\Support\Money;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasOrder;
use Modules\Support\Traits\HasUuid;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property string $label
 * @property int $option_id
 * @property Money|float $price
 * @property-read Ingredientable[] $ingredients
 * @property PriceType $price_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class OptionValue extends Model
{
    use HasCreatedBy,
        Translatable,
        HasOrder,
        HasUuid,
        HasFactory,
        HasBranch,
        HasBranchCurrency,
        HasActivityLog;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'id',
        'label',
        'option_id',
        'price_type',
        'price',
        self::ORDER_COLUMN_NAME,
        self::BRANCH_COLUMN_NAME
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['label'];

    protected static function newFactory(): OptionValueFactory
    {
        return OptionValueFactory::new();
    }

    /**
     * Get price
     *
     * @return Attribute
     */
    public function price(): Attribute
    {
        return Attribute::get(fn($price) => $this->price_type->isPercent() ? $price : new Money($price ?: 0, $this->currency));
    }

    /**
     * Get option
     *
     * @return BelongsTo
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class)
            ->withOutGlobalBranchPermission()
            ->withTrashed();
    }

    /**
     * Get ingredients
     *
     * @return MorphMany
     */
    public function ingredients(): MorphMany
    {
        return $this->morphMany(Ingredientable::class, 'ingredientable')
            ->orderBy('order');
    }

    /**
     * Price for product
     *
     * @param Product $product
     * @return Money
     */
    public function priceForProduct(Product $product): Money
    {
        if ($this->price_type->isFixed()) {
            return $this->price;
        }

        return $this->getPercentOf($product->selling_price->amount());
    }

    /**
     * Get percent of
     *
     * @param float $productPrice
     * @return Money
     */
    private function getPercentOf(float $productPrice): Money
    {
        return Money::inDefaultCurrency(($this->price / 100) * $productPrice);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            "price_type" => PriceType::class,
        ];
    }
}
