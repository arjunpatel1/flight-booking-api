<?php

namespace Modules\Order\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Discount\Models\Discount;
use Modules\Loyalty\Models\LoyaltyGift;
use Modules\Order\Enums\DiscountType;
use Modules\Support\Eloquent\Model;
use Modules\Support\Money;
use Modules\Support\Traits\HasFilters;
use Modules\Voucher\Models\Voucher;

/**
 * @property int $id
 * @property-read string $name
 * @property int $order_id
 * @property-read Order $order
 * @property int|null $loyalty_gift_id
 * @property-read LoyaltyGift|null $gift
 * @property int $discountable_id
 * @property string $discountable_type
 * @property-read Discount|Voucher $discountable
 * @property DiscountType $type
 * @property string $currency
 * @property float $currency_rate
 * @property Money $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrderDiscount extends Model
{
    use HasFilters;

    /**
     * Default date column
     *
     * @var string
     */
    public static string $defaultDateColumn = 'order_discounts.created_at';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "order_id",
        "discountable_id",
        "discountable_type",
        "loyalty_gift_id",
        "currency",
        "currency_rate",
        "amount",
        "type"
    ];

    /**
     * The relations to eager load on every query.
     *
     * @var array
     */
    protected $with = ['discountable'];

    /**
     * Get discountable
     *
     * @return MorphTo
     */
    public function discountable(): MorphTo
    {
        return $this->morphTo()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    /**
     * Get order
     *
     * @return BelongsTo<Order,static>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get amount
     *
     * @return Attribute
     */
    public function amount(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }

    /**
     * Get the order discountable name.
     *
     * @return Attribute
     */
    public function name(): Attribute
    {
        return Attribute::get(get: fn() => $this->relationLoaded('discountable')
            ? ($this->discountable_type == Voucher::class ? $this->discountable->code : $this->discountable->name)
            : 'Unknown Discount'
        );
    }

    /**
     * Get loyalty gift
     *
     * @return BelongsTo
     */
    public function gift(): BelongsTo
    {
        return $this->belongsTo(LoyaltyGift::class, 'loyalty_gift_id');
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "status",
            "payment_status",
            "type",
            "discount_type",
            "from",
            "to",
            "group_by_date",
            "branch_id"
        ];
    }

    /**
     * Scope a query to get by branch.
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeBranchId(Builder $query, string $value): void
    {
        $query->whereHas("order", function (Builder $query) use ($value) {
            $query->where('branch_id', $value);
        });
    }

    /**
     * Scope a query to get by order type.
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeType(Builder $query, string $value): void
    {
        $query->whereHas("order", function (Builder $query) use ($value) {
            $query->where('type', $value);
        });
    }

    /**
     * Scope a query to get by payment status.
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopePaymentStatus(Builder $query, string $value): void
    {
        $query->whereHas("order", function (Builder $query) use ($value) {
            $query->where('payment_status', $value);
        });
    }

    /**
     * Scope a query to get by order status.
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeStatus(Builder $query, string $value): void
    {
        $query->whereHas("order", function (Builder $query) use ($value) {
            $query->where('status', $value);
        });
    }

    /**
     * Scope a query to get by discount type.
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeDiscountType(Builder $query, string $value): void
    {
        $query->where('type', $value);
    }

    /**
     * Scope a query to search discounts or vouchers.
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeSearch(Builder $query, string $value): void
    {
        $query->whereHasMorph(
            'discountable',
            [Discount::class, Voucher::class],
            function (Builder $query) use ($value) {
                $query->whereLikeTranslation('name', $value);

                if ($query->getModel() instanceof Voucher) {
                    $query->orWhere('code', 'like', "%$value%");
                }
            }
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            "type" => DiscountType::class,
            "amount" => "float",
            "currency_rate" => "float",
            "start_date" => "datetime",
            "end_date" => "datetime",
        ];
    }
}
