<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Modules\Option\Models\Option;
use Modules\Option\Models\OptionValue;
use Modules\Product\Models\Product;
use Modules\Support\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $name
 * @property int $order_product_id
 * @property-read OrderProduct $orderProduct
 * @property-read Collection<OptionValue> $values
 * @property int $option_id
 * @property-read  Option $option
 * @property string|null $value
 */
class OrderProductOption extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'order_product_id',
        'option_id',
        'value',
    ];

    /**
     * The relations to eager load on every query.
     *
     * @var array
     */
    protected $with = ['option', 'values'];

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
     * Get the option name.
     *
     * @return Attribute
     */
    public function name(): Attribute
    {
        return Attribute::get(get: fn() => $this->relationLoaded('option') ? $this->option->name : 'Unknown Option');
    }

    /**
     * Store values
     *
     * @param Product $product
     * @param Collection $values
     * @param string $currency
     * @param int $currencyRate
     * @return void
     */
    public function storeValues(Product $product, Collection $values, string $currency, int $currencyRate): void
    {
        $values = $values->mapWithKeys(
            fn(OptionValue $value) => [
                $value->id =>
                    [
                        'currency' => $currency,
                        'currency_rate' => $currencyRate,
                        'price' => $value->priceForProduct($product)->amount(),
                    ]
            ]
        );

        $this->values()->attach($values->all());
    }

    /**
     * Get values
     *
     * @return BelongsToMany
     */
    public function values(): BelongsToMany
    {
        return $this->belongsToMany(OptionValue::class, 'order_product_option_values')
            ->using(OrderProductOptionValue::class)
            ->withPivot(['price', 'currency_rate', 'currency']);
    }
}
