<?php

namespace Modules\Invoice\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Order\Models\OrderProduct;
use Modules\Support\Eloquent\Model;
use Modules\Support\Money;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property int $invoice_id
 * @property int|null $order_product_id
 * @property string $description
 * @property string|null $sku
 * @property string $currency
 * @property float $currency_rate
 * @property Money $unit_price
 * @property float $quantity
 * @property Money $tax_amount
 * @property Money $line_total_excl_tax
 * @property Money $line_total_incl_tax
 *
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read Invoice $invoice
 * @property-read OrderProduct|null $orderProduct
 */
class InvoiceLine extends Model
{
    use SoftDeletes, Translatable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'invoice_id',
        'order_product_id',
        'description',
        'sku',
        'unit_price',
        'quantity',
        'tax_amount',
        'currency',
        'currency_rate',
        'line_total_excl_tax',
        'line_total_incl_tax',
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['description'];

    /**
     * The invoice this line belongs to.
     *
     * @return BelongsTo
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The related order product (if any).
     * Nullable because not all lines are tied to order products
     * (e.g. manual adjustments, extra fees, etc.).
     *
     * @return BelongsTo
     */
    public function orderProduct(): BelongsTo
    {
        return $this->belongsTo(OrderProduct::class);
    }
    
    /**
     * Get unit price
     *
     * @return Attribute<Money,static>
     */
    public function unitPrice(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }

    /**
     * Get tax amount
     *
     * @return Attribute<Money,static>
     */
    public function taxAmount(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }

    /**
     * Get line total excl tax
     *
     * @return Attribute<Money,static>
     */
    public function lineTotalExclTax(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }

    /**
     * Get line total incl tax
     *
     * @return Attribute<Money,static>
     */
    public function lineTotalInclTax(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }

}
