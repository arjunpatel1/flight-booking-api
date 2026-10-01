<?php

namespace Modules\Order\Models;

use Carbon\Carbon;
use DB;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Aggregator\Enums\AggregatorProvider;
use Modules\Aggregator\Models\AggregatorOrderMapping;
use Modules\Branch\Traits\HasBranch;
use Modules\Cart\CartDiscount;
use Modules\Cart\CartItem;
use Modules\Cart\CartTax;
use Modules\Currency\Currency;
use Modules\Invoice\Enums\InvoiceKind;
use Modules\Invoice\Models\Invoice;
use Modules\Loyalty\Services\LoyaltyGift\LoyaltyGiftServiceInterface;
use Modules\Order\Database\Factories\OrderFactory;
use Modules\Order\Enums\DiscountType;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\DeliveryStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Events\OrderPaid;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Enums\PaymentType;
use Modules\Payment\Models\Payment;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Services\PosCashMovement\PosCashMovementServiceInterface;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\TableMerge;
use Modules\Support\Eloquent\Model;
use Modules\Support\Enums\PriceType;
use Modules\Support\Money;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Tax\Models\Tax;
use Modules\Tax\Services\TaxCalculationService;
use Modules\User\Models\User;
use Modules\Voucher\Models\Voucher;
use Throwable;

/**
 * @property int $id
 * @property int|null $table_id
 * @property-read Table|null $table
 * @property int|null $waiter_id
 * @property-read User|null $waiter
 * @property int|null $cashier_id
 * @property-read User|null $cashier
 * @property int|null $pos_register_id
 * @property-read PosRegister|null $posRegister
 * @property int|null $table_merge_id
 * @property-read TableMerge|null $tableMerge
 * @property int|null $merged_into_order_id
 * @property-read Order|null $mergedIntoOrder
 * @property int|null $customer_id
 * @property-read User|null $customer
 * @property int|null $merged_by
 * @property-read PosRegister|null $mergedBy
 * @property-read Collection<OrderTax> $taxes
 * @property-read Collection<OrderProduct> $products
 * @property int|null $pos_session_id
 * @property-read PosSession|null $posSession
 * @property string $reference_no
 * @property string $order_number
 * @property-read  OrderStatus|null $previous_status
 * @property OrderStatus $status
 * @property-read  OrderStatus|null $next_status
 * @property OrderType $type
 * @property OrderPaymentStatus $payment_status
 * @property-read OrderDiscount $discount
 * @property-read Collection|Invoice[] $invoices
 * @property-read Collection|Invoice[] $mergedInvoices
 * @property-read Collection|Invoice[] $all_invoices
 *
 * @property string $currency
 * @property float $currency_rate
 * @property Money $subtotal
 * @property Money $total
 * @property Money $due_amount
 * @property Money $cost_price
 * @property Money $revenue
 * @property int $guest_count
 * @property string|null $notes
 * @property string|null $car_plate
 * @property string|null $car_description
 * @property Carbon $order_date
 * @property boolean $is_stock_deducted
 * @property int|null $modified_by
 * @property User|null $modifiedBy
 * @property-read Collection|Payment[] $payments
 * @property Carbon|null $modified_at
 * @property Carbon|null $served_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $merged_at
 * @property Carbon|null $payment_at
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder|static|self activeOrders()
 * @method static Builder|static|self forMerge()
 * @method static Builder|static|self completed()
 * @method static Builder|static|self withoutCanceledOrders()
 * @method static Builder|static|self visibleForKitchen()
 * @method static Builder|static|self forKitchenCategories(array $categoryIds)
 */
class Order extends Model
{
    public static function revenueExcludedStatuses(): array
    {
        return [
            OrderStatus::Cancelled->value,
            OrderStatus::Refunded->value,
            OrderStatus::Merged->value,
        ];
    }
    use SoftDeletes;
    use HasFactory,
        HasCreatedBy,
        HasFilters,
        HasSortBy,
        HasTagsCache,
        HasBranch,
        Concerns\HasOrderScopes,
        Concerns\HasOrderRelations,
        Concerns\HasOrderTaxes,
        Concerns\HasOrderPayments,
        Concerns\HasOrderStatusFlow,
        Concerns\HasOrderCalculations;

    /**
     * Default date column
     *
     * @var string
     */
    public static string $defaultDateColumn = 'created_at';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'branch_id',
        'merged_into_order_id',
        'split_from_order_id',
        'table_id',
        'table_merge_id',
        'pos_register_id',
        'pos_session_id',
        'merged_by',
        'waiter_id',
        'cashier_id',
        'reference_no',
        'gstin',
        'customer_gstin_name',
        'order_number',
        'status',
        'type',
        'payment_status',
        'currency',
        'currency_rate',
        'subtotal',
        'total',
        'due_amount',
        'customer_id',
        'scheduled_at',
        'guest_count',
        'notes',
        'fulfilment',
        'kitchen_display',
        'is_rush',
        'order_date',
        'is_stock_deducted',
        'cost_price',
        'revenue',
        'served_at',
        'closed_at',
        'merged_at',
        'payment_at',
        'modified_at',
        'modified_by',
    ];

    /**
     * Get total sales (converted to default currency if needed)
     *
     * @param string|null $currency
     * @return Money
     */
    public static function totalSales(?string $currency = null): Money
    {
        if (auth()->check() && auth()->user()->assignedToBranch()) {
            $total = self::withoutCanceledOrders()->sum('total');
        } else {
            $total = self::withoutCanceledOrders()
                ->selectRaw('SUM(total * COALESCE(currency_rate, 1)) AS total_sales')
                ->value('total_sales') ?? 0;
        }

        return is_null($currency) ? Money::inDefaultCurrency($total) : new Money($total, $currency);
    }

    /**
     * Get average order value (converted to default currency if needed)
     *
     * @param string|null $currency
     * @return Money
     */
    public static function averageOrderValue(?string $currency = null): Money
    {
        if (auth()->check() && auth()->user()->assignedToBranch()) {
            $avg = self::withoutCanceledOrders()->average('total') ?? 0;
        } else {
            $avg = self::withoutCanceledOrders()
                ->selectRaw('AVG(total * COALESCE(currency_rate, 1)) AS avg_order')
                ->value('avg_order') ?? 0;
        }

        return is_null($currency) ? Money::inDefaultCurrency($avg) : new Money($avg, $currency);
    }

    /**
     * Boot the model and set reference/order numbers on create.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::updating(function (Order $order): void {
            $original = (array) $order->getOriginal('fulfilment');
            if ($order->getOriginal('type') !== OrderType::Delivery || ! array_key_exists('customer_delivery_fee', $original)) return;
            $current = $order->fulfilmentDetails();
            foreach (['customer_delivery_fee', 'delivery_distance_km', 'delivery_pricing_rule', 'delivery_address'] as $key) {
                if (($original[$key] ?? null) != ($current[$key] ?? null)) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['delivery' => 'The checkout delivery snapshot cannot be changed by order editing.']);
                }
            }
            if ($order->isDirty(['branch_id', 'type', 'currency'])
                || data_get($original, 'additional_payments.customer_delivery_fee') != data_get($current, 'additional_payments.customer_delivery_fee')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['delivery' => 'Cancel and recreate the order to change its delivery outlet, order type or fee.']);
            }
        });

        static::creating(function (Order $order) {
            $order->reference_no = static::generateReferenceNo();
            $order->order_number = static::generateOrderNumber(
                $order->branch_id,
                $order->order_date?->toDateString(),
            );
        });
    }

    /**
     * Generate reference no
     *
     * @return string
     */
    public static function generateReferenceNo(): string
    {
        // This reference is a bearer capability on public tracking and
        // feedback surfaces. Use CSPRNG entropy; order_number is deliberately
        // separate and may remain human-readable/sequential.
        return 'ORD-'.Str::upper(Str::random(20));
    }

    /**
     * Generate order number with race condition protection
     *
     * @param int $branchId
     * @param string|null $orderDate
     * @return string
     */
    public static function generateOrderNumber(int $branchId, ?string $orderDate = null): string
    {
        return DB::transaction(function () use ($branchId, $orderDate) {
            $date = $orderDate ?: today()->toDateString();

            // Count-based numbering can reuse an existing number after an order is
            // deleted. Lock the latest row and advance its numeric value instead.
            $latestNumber = Order::withTrashed()
                ->where('branch_id', $branchId)
                ->whereDate('order_date', $date)
                ->orderByRaw('CAST(order_number AS UNSIGNED) DESC')
                ->lockForUpdate()
                ->value('order_number');

            $nextNumber = max(0, (int) $latestNumber) + 1;

            $orderNumber = str_pad((string) $nextNumber, $nextNumber < 100 ? 2 : 3, '0', STR_PAD_LEFT);

            return $orderNumber;
        });
    }

    /**
     * Get customer name
     * @return string|null
     */
    public function getCustomerName(): ?string
    {
        return $this->relationLoaded("customer") ? ($this->customer?->name ?: User::walkInName()) : null;
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "status",
            "payment_status",
            "type",
            "delivery_status",
            "source",
            "from",
            "to",
            "group_by_date",
            "customer_id",
            "waiter_id",
            self::BRANCH_COLUMN_NAME
        ];
    }

    public function scopeDeliveryStatus(Builder $query, string $status): void
    {
        if ($status !== DeliveryStatus::Delivered->value) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereHas('delivery', fn (Builder $delivery) => $delivery->where('status', $status));
    }

    public function getInvoice($with = []): ?Invoice
    {
        return Invoice::with($with)
            ->where('invoice_kind', InvoiceKind::Standard)
            ->when(
                is_null($this->table_merge_id),
                fn($query) => $query->where('order_id', $this->id)
            )
            ->when(
                !is_null($this->table_merge_id),
                fn($query) => $query->where('table_merge_id', $this->table_merge_id)
            )
            ->first();
    }

    /**
     * All distinct invoices using Laravel 12 Attribute Cast.
     */
    protected function allInvoices(): Attribute
    {
        return Attribute::get(function () {
            $invoices = $this->relationLoaded('invoices')
                ? $this->getRelation('invoices')
                : collect();

            $merged = $this->relationLoaded('mergedInvoices')
                ? $this->getRelation('mergedInvoices')
                : collect();

            return $invoices
                ->merge($merged)
                ->unique('id')
                ->values();
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
            "fulfilment" => "array",
            "status" => OrderStatus::class,
            "type" => OrderType::class,
            "payment_status" => OrderPaymentStatus::class,
            "order_date" => "date",
            "served_at" => "datetime",
            "closed_at" => "datetime",
            "merged_at" => "datetime",
            "payment_at" => "datetime",
            "modified_at" => "datetime",
            "is_stock_deducted" => "boolean",
            "start_date" => "datetime",
            "end_date" => "datetime",
            "scheduled_at" => "datetime",
        ];
    }

    public function getNotesAttribute(?string $value): ?string
    {
        return ($this->attributes['fulfilment'] ?? null) !== null
            ? $value
            : \Modules\Order\Support\OnlineOrderDetails::legacy($value)['notes'];
    }

    public function fulfilmentDetails(): array
    {
        return $this->fulfilment ?? \Modules\Order\Support\OnlineOrderDetails::legacy($this->attributes['notes'] ?? null)['fulfilment'];
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "reference_no",
            "order_number",
            "status",
            "type",
            "total",
            "payment_status",
            "customer_id",
            self::BRANCH_COLUMN_NAME,
        ];
    }
}
