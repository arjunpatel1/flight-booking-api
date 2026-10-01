<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Pricing\Models\PriceType;
use Modules\Support\Eloquent\Model;

/**
 * @property int $id
 * @property int $product_id
 * @property int $price_type_id
 * @property float $price
 * @property bool $is_global
 * @property-read PriceType $priceType
 * @property-read Product $product
 */
class ProductPrice extends Model
{
    protected $fillable = [
        'product_id',
        'price_type_id',
        'price',
        'is_global',
    ];

    protected $casts = [
        'price'     => 'float',
        'is_global' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceType(): BelongsTo
    {
        return $this->belongsTo(PriceType::class);
    }
}
