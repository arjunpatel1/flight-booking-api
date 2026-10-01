<?php

namespace Modules\Pos\Models;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Branch\Traits\HasBranchCurrency;
use Modules\Currency\Currency;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Pos\Database\Factories\PosSessionFactory;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Services\PosCashMovement\PosCashMovementServiceInterface;
use Modules\Support\Eloquent\Model;
use Modules\Support\Money;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\User\Models\User;
use Throwable;

/**
 * @property int $id
 * @property int $pos_register_id
 * @property-read PosRegister $posRegister
 * @property int $opened_by
 * @property-read User $openedBy
 * @property int|null $closed_by
 * @property-read User $closedBy
 * @property PosSessionStatus $status
 * @property Money $opening_float
 * @property Money|null $declared_cash
 * @property Money|null $system_cash_sales
 * @property Money|null $cash_over_short
 * @property Money|null $system_card_sales
 * @property Money|null $system_other_sales
 * @property Money|null $total_sales
 * @property Money|null $total_refunds
 * @property int $orders_count
 * @property array|null $meta
 * @property string $notes
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class PosSession extends Model
{
    use SoftDeletes,
        HasCreatedBy,
        HasSortBy,
        HasBranch,
        HasBranchCurrency,
        HasFilters,
        HasFactory,
        HasActivityLog;

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
        "pos_register_id",
        "opened_by",
        "closed_by",
        "status",
        "opening_float",
        "declared_cash",
        "system_cash_sales",
        "cash_over_short",
        "system_card_sales",
        "system_other_sales",
        "total_sales",
        "total_refunds",
        "orders_count",
        "meta",
        "notes",
        "opened_at",
        "closed_at",
        self::BRANCH_COLUMN_NAME
    ];

    protected static function newFactory(): PosSessionFactory
    {
        return PosSessionFactory::new();
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            "status",
            "pos_register_id",
            "group_by_date",
            self::BRANCH_COLUMN_NAME
        ];
    }

    /**
     * Get system card sales
     *
     * @return Attribute
     */
    public function systemCardSales(): Attribute
    {
        return Attribute::get(fn($amount) => is_null($amount) ? null : new Money($amount, $this->currency));
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
        $query->whereHas("posRegister", function (Builder $query) use ($value) {
            $query->whereLikeTranslation('name', $value);
        });
    }

    /**
     * Get system wallet sales
     *
     * @return Attribute
     */
    public function systemWalletSales(): Attribute
    {
        return Attribute::get(fn($amount) => is_null($amount) ? null : new Money($amount, $this->currency));
    }

    /**
     * Get system voucher sales
     *
     * @return Attribute
     */
    public function systemVoucherSales(): Attribute
    {
        return Attribute::get(fn($amount) => is_null($amount) ? null : new Money($amount, $this->currency));
    }

    /**
     * Get system other sales
     *
     * @return Attribute
     */
    public function systemOtherSales(): Attribute
    {
        return Attribute::get(fn($amount) => is_null($amount) ? null : new Money($amount, $this->currency));
    }

    /**
     * Get total sales
     *
     * @return Attribute
     */
    public function totalSales(): Attribute
    {
        return Attribute::get(fn($amount) => is_null($amount) ? null : new Money($amount, $this->currency));
    }

    /**
     * Get opening float
     *
     * @return Attribute
     */
    public function openingFloat(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }

    /**
     * Get declared cash
     *
     * @return Attribute
     */
    public function declaredCash(): Attribute
    {
        return Attribute::get(fn($amount) => is_null($amount) ? null : new Money($amount, $this->currency));
    }

    /**
     * Get cash over short
     *
     * @return Attribute
     */
    public function cashOverShort(): Attribute
    {
        return Attribute::get(fn($amount) => is_null($amount) ? null : new Money($amount, $this->currency));
    }

    /**
     * Get total refunds
     *
     * @return Attribute
     */
    public function totalRefunds(): Attribute
    {
        return Attribute::get(fn($amount) => is_null($amount) ? null : new Money($amount, $this->currency));
    }

    /**
     * Get system cash sales
     *
     * @return Attribute
     */
    public function systemCashSales(): Attribute
    {
        return Attribute::get(fn($amount) => is_null($amount) ? null : new Money($amount, $this->currency));
    }

    /**
     * Get pos register
     *
     * @return BelongsTo
     */
    public function posRegister(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class)
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    /**
     * Get opened by
     *
     * @return BelongsTo
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, "opened_by")
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    /**
     * Get closed by
     *
     * @return BelongsTo
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, "closed_by")
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    /**
     * Determine if a session is closed
     *
     * @return bool
     */
    public function isClosed(): bool
    {
        return $this->status == PosSessionStatus::Closed;
    }

    /**
     * Calculate closing summary for POS session.
     *
     * @return array
     * @throws Throwable
     */
    public function calculateClosingSummary(): array
    {
        $orders = $this->orders()
            ->with('payments')
            ->get();

        $systemCashSales = 0;
        $systemCardSales = 0;
        $systemOtherSales = 0;
        $totalRefunds = 0;
        $ordersCount = $orders->count();

        /** @var Order $order */
        foreach ($orders as $order) {
            foreach ($order->payments as $payment) {
                switch ($payment->method) {
                    case PaymentMethod::Cash:
                        $systemCashSales += $payment->amount->amount();
                        break;
                    case PaymentMethod::Card:
                        $systemCardSales += $payment->amount->amount();
                        break;
                    default:
                        $systemOtherSales += $payment->amount->amount();
                        break;
                }
            }

            if ($order->status === OrderStatus::Refunded) {
                $totalRefunds += $order->total->amount();
            }
        }

        $scale = Currency::subunit($this->branch->currency);

        $declaredCash = $this->declared_cash?->amount() ?: 0;
        $cashOverShort = $declaredCash - $systemCashSales;
        $cashOverShortRound = round($declaredCash - $systemCashSales, $scale);

        $threshold = $this->branch->cash_difference_threshold->amount();

        if (abs($cashOverShortRound) > $threshold) {
            throw new Exception(
                __("pos::messages.cannot_close_session_cash_difference", [
                    'declared' => number_format($declaredCash, $scale),
                    'expected' => number_format($systemCashSales, $scale),
                    'difference' => number_format($cashOverShortRound, $scale),
                    'threshold' => number_format($threshold, $scale),
                ])
            );
        }

        if ($cashOverShortRound > 0 || $cashOverShortRound < 0) {
            app(PosCashMovementServiceInterface::class)
                ->closingAdjust(
                    session: $this,
                    amount: abs($cashOverShortRound),
                    notes: $cashOverShortRound > 0
                        ? __("pos::messages.cash_over_short_note_over")
                        : __("pos::messages.cash_over_short_note_short")
                );
        }

        return [
            'system_cash_sales' => $systemCashSales,
            'system_card_sales' => $systemCardSales,
            'system_other_sales' => $systemOtherSales,
            'total_sales' => $systemCashSales + $systemCardSales + $systemOtherSales,
            'total_refunds' => $totalRefunds,
            'orders_count' => $ordersCount,
            'cash_over_short' => $cashOverShort,
        ];
    }

    /**
     * Get orders
     *
     * @return HasMany
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)
            ->withOutGlobalBranchPermission();
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "status",
            "pos_register_id",
            "opening_float",
            "total_sales",
            "closed_by",
            "opened_by",
            "opened_at",
            "closed_at",
            self::BRANCH_COLUMN_NAME,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            "status" => PosSessionStatus::class,
            "opened_at" => "datetime",
            "closed_at" => "datetime",
            "start_date" => "datetime",
            "end_date" => "datetime",
            "meta" => "array",
        ];
    }
}
