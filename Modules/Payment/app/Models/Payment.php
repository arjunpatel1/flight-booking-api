<?php

namespace Modules\Payment\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Traits\HasBranch;
use Modules\Order\Models\Order;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentType;
use Modules\Support\Eloquent\Model;
use Modules\Support\Money;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\User\Models\User;

/**
 * @property int $id
 * @property string $order_reference_no
 * @property int $order_id
 * @property-read  Order $order
 * @property int|null $cashier_id
 * @property-read User|null $cashier
 * @property string $transaction_id
 * @property PaymentMethod $method
 * @property Money $amount
 * @property string $currency
 * @property float $currency_rate
 * @property array|null $meta
 * @property PaymentType|null $type
 * @property PaymentStatus|null $status
 * @property Carbon|null $received_at
 * @property User|null $received_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Payment extends Model
{
    public static string $defaultDateColumn = 'created_at';

    use SoftDeletes,
        HasCreatedBy,
        HasSortBy,
        HasBranch,
        HasFilters;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "order_reference_no",
        "order_id",
        "cashier_id",
        "transaction_id",
        "method",
        "amount",
        "currency",
        "currency_rate",
        "meta",
        "type",
        "received_at",
        "received_by",
        "gateway",
        "gateway_transaction_id",
        "gateway_response",
        "status",
        "processed_at",
        "refund_reason",
        "refunded_at",
        self::BRANCH_COLUMN_NAME
    ];

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            "method",
            "type",
            "status",
            "source",
            "gateway",
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
        $query->where(function (Builder $query) use ($value) {
            $query->like('order_reference_no', $value)->orLike('transaction_id', $value);
        });
    }

    public function scopeSource(Builder $query, string $source): void
    {
        $query->whereHas('order', fn (Builder $order) => $order->source($source));
    }

    public function scopeGateway(Builder $query, string $gateway): void
    {
        $gateway === 'online'
            ? $query->whereNotNull('gateway')
            : $query->where('gateway', $gateway);
    }

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
     * Get cashier
     *
     * @return BelongsTo
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id')
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    /**
     * Get received by
     *
     * @return BelongsTo
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by')
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    /**
     * Get amount
     *
     * @return Attribute
     */
    public function amount(): Attribute
    {
        return Attribute::get(fn($amount) => !is_null($amount) ? new Money($amount, $this->currency) : null);
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'start_date' => "datetime",
            "end_date" => "datetime",
            'method' => PaymentMethod::class,
            'type' => PaymentType::class,
            'status' => PaymentStatus::class,
            'received_at' => "datetime",
            'gateway_response' => 'array',
            'processed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "order_reference_no",
            "method",
            "amount",
            "transaction_id",
            self::BRANCH_COLUMN_NAME,
        ];
    }
}
