<?php

namespace Modules\Loyalty\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Loyalty\Database\Factories\LoyaltyCustomerFactory;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Translation\Traits\Translatable;
use Modules\User\Models\User;

/**
 * @property int $id
 * @property int $customer_id
 * @property User $customer
 * @property int $loyalty_program_id
 * @property LoyaltyProgram $loyaltyProgram
 * @property int|null $loyalty_tier_id
 * @property LoyaltyTier|null $loyaltyTier
 * @property int $points_balance
 * @property string $points_balance_format
 * @property int $lifetime_points
 * @property Carbon|null $last_earned_at
 * @property Carbon|null $last_redeemed_at
 * @property bool $force
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class LoyaltyCustomer extends Model
{
    use HasSortBy,
        HasFactory,
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
    protected array $translatable = ['program_name', 'tier_name'];

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "customer_id",
        "loyalty_program_id",
        "loyalty_tier_id",
        "points_balance",
        "lifetime_points",
        "last_earned_at",
        "last_redeemed_at",
        "force"
    ];

    protected static function newFactory(): LoyaltyCustomerFactory
    {
        return LoyaltyCustomerFactory::new();
    }

    /**
     * Get customer
     *
     * @return BelongsTo
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, "customer_id")
            ->withTrashed()
            ->withoutGlobalActive();
    }

    /**
     * Get loyalty program
     *
     * @return BelongsTo
     */
    public function loyaltyProgram(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class)
            ->withTrashed()
            ->withoutGlobalActive();
    }

    /**
     * Get loyalty tier
     *
     * @return BelongsTo
     */
    public function loyaltyTier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class)
            ->with("files")
            ->withTrashed()
            ->withoutGlobalActive();
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "customer_id",
            "loyalty_program_id",
            "loyalty_tier_id",
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
            $query->like('name', $value)
                ->orLike('email', $value)
                ->orLike('username', $value);
        });
    }

    /**
     * Get points balance format
     * @return Attribute
     */
    public function pointsBalanceFormat(): Attribute
    {
        return Attribute::get(fn() => "$this->points_balance Pts");
    }

    /**
     * Scope by loyalty program id
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeLoyaltyProgramId(Builder $query, string $value): void
    {
        $query->where('loyalty_customers.loyalty_program_id', $value);
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "customer_id",
            "loyalty_program_id",
            "loyalty_tier_id",
            "points_balance",
            "lifetime_points",
            "last_earned_at",
            "last_redeemed_at"
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
            "points_balance" => "int",
            "lifetime_points" => "int",
            "last_earned_at" => "datetime",
            "last_redeemed_at" => "datetime",
            "force" => "boolean",
            "start_date" => "datetime",
            "end_date" => "datetime",
        ];
    }
}
