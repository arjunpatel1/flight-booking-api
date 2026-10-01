<?php

namespace Modules\Invoice\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Order\Enums\DiscountType;
use Modules\Support\Eloquent\Model;
use Modules\Support\Money;
use Modules\Translation\Traits\Translatable;


/**
 * @property int $id
 * @property int $invoice_id
 * @property string|null $discountable_type
 * @property int|null $discountable_id
 * @property DiscountType $source
 * @property array|null $name
 * @property string $currency
 * @property float $currency_rate
 * @property Money $amount
 * @property bool $applied_before_tax
 *
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * @property-read Invoice $invoice
 * @property-read Model|null $discountable
 */
class InvoiceDiscount extends Model
{
    use SoftDeletes, Translatable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'invoice_id',
        'discountable_type',
        'discountable_id',
        'source',
        'name',
        'currency',
        'currency_rate',
        'amount',
        'applied_before_tax'
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name'];

    /**
     * Get amount
     *
     * @return Attribute<Money,static>
     */
    public function amount(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }

    /**
     * The invoice this discount belongs to.
     *
     * @return BelongsTo<Invoice,static>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Polymorphic relation for discount sources (Discount, Voucher, etc.).
     *
     * @return MorphTo
     */
    public function discountable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => DiscountType::class,
            'applied_before_tax' => 'boolean',
        ];
    }
}
