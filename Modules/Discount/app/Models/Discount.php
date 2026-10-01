<?php

namespace Modules\Discount\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Branch\Traits\HasBranchCurrency;
use Modules\Cart\Cart;
use Modules\Cart\Facades\Cart as FacadeCart;
use Modules\Discount\Database\Factories\DiscountFactory;
use Modules\Loyalty\Models\LoyaltyGift;
use Modules\Order\Models\Order;
use Modules\Support\Eloquent\Model;
use Modules\Support\Enums\PriceType;
use Modules\Support\Money;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property string $name
 * @property string $description
 * @property PriceType $type
 * @property int|Money $value
 * @property Money|null $minimum_spend
 * @property Money|null $maximum_spend
 * @property Money|null $max_discount
 * @property array|null $conditions
 * @property int|null $usage_limit
 * @property int|null $per_customer_limit
 * @property array|null $meta
 * @property-read bool $used
 * @property-read LoyaltyGift|null $gift
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $deleted_at
 */
class Discount extends Model
{
    use HasActiveStatus,
        HasActivityLog,
        HasFactory,
        HasCreatedBy,
        HasBranch,
        HasTagsCache,
        HasSortBy,
        HasFilters,
        HasBranchCurrency,
        Translatable,
        SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'description',
        'value',
        'type',
        'conditions',
        'minimum_spend',
        'maximum_spend',
        'max_discount',
        'usage_limit',
        'per_customer_limit',
        'meta',
        'start_date',
        'end_date',
        'ends_at',
        self::ACTIVE_COLUMN_NAME,
        self::BRANCH_COLUMN_NAME
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name', 'description'];

    /**
     * Get a list of all discounts.
     *
     * @param int|null $branchId
     * @return Collection
     */
    public static function list(?int $branchId = null): Collection
    {
        $discounts = Cache::tags("discounts")
            ->rememberForever(
                makeCacheKey([
                    'discounts',
                    is_null($branchId) ? 'all' : "branch-$branchId",
                    'list'
                ]),
                fn() => static::select(
                    'id',
                    'name',
                    'value',
                    'start_date',
                    'end_date',
                    'type',
                )
                    ->whereNull("meta->reward_id")
                    ->latest()
                    ->when($branchId !== null, fn($q) => $q
                        ->withOutGlobalBranchPermission()
                        ->where(function ($q) use ($branchId) {
                            $q->where('branch_id', $branchId)
                                ->orWhereNull('branch_id');
                        }))
                    ->get()
            );

        return $discounts
            ->filter(fn(Discount $discount) => $discount->valid())
            ->map(fn(Discount $discount) => [
                'id' => $discount->id,
                'name' => "$discount->name ({$discount->getValueText()})",
            ])
            ->values();
    }

    /**
     * Determine if discount is valid
     *
     * @return bool
     */
    public function valid(): bool
    {
        if ($this->hasStartDate() && $this->hasEndDate()) {
            return $this->startDateIsValid() && $this->endDateIsValid();
        }

        if ($this->hasStartDate()) {
            return $this->startDateIsValid();
        }

        if ($this->hasEndDate()) {
            return $this->endDateIsValid();
        }

        return true;
    }

    /**
     * Determine if it has a start date
     *
     * @return bool
     */
    private function hasStartDate(): bool
    {
        return !is_null($this->start_date);
    }

    /**
     * Determine if it has an end date
     *
     * @return bool
     */
    private function hasEndDate(): bool
    {
        return !is_null($this->end_date);
    }

    /**
     * Determine if it has a start date is valid
     *
     * @return bool
     */
    private function startDateIsValid(): bool
    {
        return today() >= $this->start_date;
    }

    /**
     * Determine if it has an end is valid
     *
     * @return bool
     */
    private function endDateIsValid(): bool
    {
        return today() <= $this->end_date;
    }

    /**
     * Get value text
     *
     * @return string
     */
    public function getValueText(): string
    {
        return $this->type == PriceType::Fixed ? $this->value->format() : round($this->value, 3) . "%";
    }

    /**
     * Boot the model and handle stock adjustments on create, update, and delete.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::saving(function (Discount $discount) {
            if ($discount->type == PriceType::Fixed) {
                $discount->max_discount = null;
            }
        });
    }

    protected static function newFactory(): DiscountFactory
    {
        return DiscountFactory::new();
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            "type",
            self::ACTIVE_COLUMN_NAME,
            self::BRANCH_COLUMN_NAME
        ];
    }

    /**
     * Get minimum spend
     *
     * @return Attribute<Money|null>
     */
    public function minimumSpend(): Attribute
    {
        return Attribute::get(
            fn($minimumSpend) => !is_null($minimumSpend)
                ? Money::inDefaultCurrency($minimumSpend)
                : null
        );
    }

    /**
     * Get minimum spend
     *
     * @return Attribute<Money|null>
     */
    public function maximumSpend(): Attribute
    {
        return Attribute::get(
            fn($maximumSpend) => !is_null($maximumSpend)
                ? Money::inDefaultCurrency($maximumSpend)
                : null
        );
    }

    /**
     * Get max discount
     *
     * @return Attribute<Money|null>
     */
    public function maxDiscount(): Attribute
    {
        return Attribute::get(
            fn($maxDiscount) => !is_null($maxDiscount)
                ? Money::inDefaultCurrency($maxDiscount)
                : null
        );
    }

    /**
     * Get value
     *
     * @return Attribute<Money|float>
     */
    public function value(): Attribute
    {
        return Attribute::get(
            fn($value) => $this->type == PriceType::Fixed
                ? Money::inDefaultCurrency($value)
                : $value
        );
    }

    /**
     * Determine if discount invalid
     * @return bool
     */
    public function invalid(): bool
    {
        return !$this->valid();
    }

    /**
     * Did not spend the required amount
     *
     * @param Cart|FacadeCart $cart
     * @return bool
     */
    public function didNotSpendTheRequiredAmount(Cart|FacadeCart $cart): bool
    {
        if (is_null($this->minimum_spend)) {
            return false;
        }

        $branch = $cart instanceof Cart ? $cart->branch() : $cart::branch();

        if (is_null($branch)) {
            return true;
        }

        $minimumSpend = $this->minimum_spend->convert($branch->currency());


        return $cart instanceof Cart
            ? $cart->subTotal()->lessThan($minimumSpend)
            : $cart::subTotal()->lessThan($minimumSpend);
    }

    /**
     * Spent more than a maximum amount
     *
     * @param Cart|FacadeCart $cart
     * @return bool
     */
    public function spentMoreThanMaximumAmount(Cart|FacadeCart $cart): bool
    {
        if (is_null($this->maximum_spend)) {
            return false;
        }

        $branch = $cart instanceof Cart ? $cart->branch() : $cart::branch();

        if (is_null($branch)) {
            return true;
        }

        $maximumSpend = $this->maximum_spend->convert($branch->currency());

        return $cart instanceof Cart
            ? $cart->subTotal()->greaterThan($maximumSpend)
            : $cart::subTotal()->greaterThan($maximumSpend);
    }

    /**
     * Usage limit reached
     *
     * @param int|null $userId
     * @param int|null $exceptOrderId
     * @return bool
     */
    public function usageLimitReached(?int $userId = null, ?int $exceptOrderId = null): bool
    {
        return $this->perDiscountUsageLimitReached($exceptOrderId) || $this->perCustomerUsageLimitReached($userId, $exceptOrderId);
    }

    /**
     * per discount usage limit reached
     *
     * @param int|null $exceptOrderId
     * @return bool
     */
    public function perDiscountUsageLimitReached(?int $exceptOrderId = null): bool
    {
        if (is_null($this->usage_limit)) {
            return false;
        }

        return ($this->used - (!is_null($exceptOrderId) ? 1 : 0)) >= $this->usage_limit;
    }

    /**
     * Per customer usage limit reached
     *
     * @param int|null $userId
     * @param int|null $exceptOrderId
     * @return bool
     */
    public function perCustomerUsageLimitReached(?int $userId = null, ?int $exceptOrderId = null): bool
    {
        if (is_null($userId) || $this->discountHasNoUsageLimitForCustomers()) {
            return false;
        }

        $used = Order::where('customer_id', $userId)
            ->whereHas(
                "discount",
                fn($query) => $query->where('discountable_type', $this->getMorphClass())
                    ->where('discountable_id', $this->id)
            )
            ->when(!is_null($exceptOrderId), fn($query) => $query->where('id', '<>', $exceptOrderId))
            ->count();

        return $used >= $this->per_customer_limit;
    }

    /**
     * Discount has no usage limit for customers
     *
     * @return bool
     */
    private function discountHasNoUsageLimitForCustomers(): bool
    {
        return is_null($this->per_customer_limit);
    }


    /**
     * Mark this discount as used once.
     *
     * @return void
     */
    public function usedOnce(): void
    {
        if ($this->exists) {
            $this->increment('used');
        }
    }

    /**
     * Decrease the usage count by one.
     *
     * @return void
     */
    public function unusedOnce(): void
    {
        if ($this->exists && $this->used > 0) {
            $this->decrement('used');
        }
    }

    /**
     * Get gift
     *
     * @return MorphOne
     */
    public function gift(): MorphOne
    {
        return $this->morphOne(LoyaltyGift::class, 'artifact');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PriceType::class,
            self::ACTIVE_COLUMN_NAME => 'boolean',
            'conditions' => 'array',
            'meta' => 'array',
            'usage_limit' => "int",
            'per_customer_limit' => "int",
            'start_date' => "date",
            'end_date' => "date",
        ];
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            'name',
            'value',
            'type',
            'starts_at',
            'ends_at',
            self::ACTIVE_COLUMN_NAME,
            self::BRANCH_COLUMN_NAME
        ];
    }
}
