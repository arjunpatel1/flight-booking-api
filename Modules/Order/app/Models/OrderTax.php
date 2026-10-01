<?php

namespace Modules\Order\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Eloquent\Model;
use Modules\Support\Money;
use Modules\Support\Traits\HasFilters;
use Modules\Tax\Enums\TaxType;
use Modules\Tax\Models\Tax;
use Modules\Translation\Traits\Translatable;


/**
 * @property int $id
 * @property int $order_id
 * @property-read  Order $order
 * @property int $order_product_id
 * @property-read  OrderProduct|null $orderProduct
 * @property int $tax_id
 * @property-read  Tax|null $tax
 * @property string $name
 * @property float $rate
 * @property string $currency
 * @property float $currency_rate
 * @property Money $amount
 * @property TaxType $type
 * @property bool $compound
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrderTax extends Model
{
    use Translatable, HasFilters;

    /**
     * Default date column
     *
     * @var string
     */
    public static string $defaultDateColumn = 'created_at';
    
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "order_id",
        "order_product_id",
        "tax_id",
        "name",
        "rate",
        "currency",
        "currency_rate",
        "compound",
        "amount",
        "type",
    ];
    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name'];

    /**
     * Get order
     *
     * @return BelongsTo
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)
            ->withOutGlobalBranchPermission();
    }

    /**
     * Get order product
     *
     * @return BelongsTo
     */
    public function orderProduct(): BelongsTo
    {
        return $this->belongsTo(OrderProduct::class);
    }

    /**
     * Get tax
     *
     * @return BelongsTo
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    /**
     * Get amount
     *
     * @return Attribute
     */
    public function amount(): Attribute
    {
        return Attribute::get(fn($amount) => isset($this->currency) ? new Money($amount, $this->currency) : Money::inDefaultCurrency($amount));
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "status",
            "payment_status",
            "type",
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
     * Scope a query to get by status.
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
     * Scope a query to search across all fields.
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeSearch(Builder $query, string $value): void
    {
        $query->whereHas("tax", function (Builder $query) use ($value) {
            $query->whereLikeTranslation('name', $value);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            "type" => TaxType::class,
            "compound" => "boolean",
            "rate" => "float",
            "start_date" => "datetime",
            "end_date" => "datetime",
        ];
    }
}
