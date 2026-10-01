<?php

namespace Modules\Loyalty\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Loyalty\Database\Factories\LoyaltyTransactionFactory;
use Modules\Loyalty\Enums\LoyaltyTransactionType;
use Modules\Order\Models\Order;
use Modules\Support\Eloquent\Model;
use Modules\Support\Money;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property int $loyalty_customer_id
 * @property LoyaltyCustomer $customer
 * @property int|null $order_id
 * @property Order $order
 * @property LoyaltyTransactionType $type
 * @property int $points
 * @property Money|null $amount
 * @property-read string|null $description
 * @property array|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class LoyaltyTransaction extends Model
{
    use HasFactory,
        HasSortBy,
        HasFilters,
        Translatable,
        SoftDeletes;

    /**
     * Default date column
     *
     * @var string
     */
    public static string $defaultDateColumn = 'created_at';

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['program_name', 'branch_name'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        "loyalty_customer_id",
        "order_id",
        "type",
        "points",
        "amount",
        "meta"
    ];

    /**
     * The attributes that should be guarded from mass assignment.
     *
     * @var array<int, string>
     */
    protected $guarded = [
        'id',
        'created_by',
        'deleted_at',
    ];

    protected static function newFactory(): LoyaltyTransactionFactory
    {
        return LoyaltyTransactionFactory::new();
    }

    /**
     * Get amount
     *
     * @return Attribute
     */
    public function amount(): Attribute
    {
        return Attribute::get(fn($amount) => is_null($amount) ? null : Money::inDefaultCurrency($amount));
    }

    /**
     * Get loyalty customer
     *
     * @return BelongsTo
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCustomer::class, 'loyalty_customer_id')
            ->withTrashed();
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

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "loyalty_customer_id",
            "type",
            "from",
            "to",
            "group_by_date"
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
        $query->whereHas("customer", function (Builder $query) use ($value) {
            $query->whereHas("customer", function (Builder $query) use ($value) {
                $query->like('name', $value)
                    ->orLike('email', $value)
                    ->orLike('username', $value);
            });
        });
    }

    /**
     * Get transaction description
     *
     * @return Attribute<string|null>
     */
    public function description(): Attribute
    {
        return Attribute::get(
            fn() => isset($this->meta['description'])
                ? is_string($this->meta['description']) ? $this->meta['description'] : (__($this->meta['description']['text'], $this->meta['description']['replace'] ?? []))
                : null
        );
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "customer_id",
            "points",
            "type",
            "amount"
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
            "type" => LoyaltyTransactionType::class,
            "meta" => "array",
            "points" => "int",
            "start_date" => "datetime",
            "end_date" => "datetime",
            "activity_date" => "datetime",
        ];
    }
}
