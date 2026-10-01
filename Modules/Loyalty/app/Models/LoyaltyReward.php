<?php

namespace Modules\Loyalty\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Loyalty\Database\Factories\LoyaltyRewardFactory;
use Modules\Loyalty\Enums\LoyaltyRewardType;
use Modules\Media\Models\Media;
use Modules\Media\Traits\HasMedia;
use Modules\Support\Eloquent\Model;
use Modules\Support\Enums\PriceType;
use Modules\Support\Money;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasOrder;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property string $name
 * @property string $description
 * @property int $loyalty_program_id
 * @property-read LoyaltyProgram $loyaltyProgram
 * @property int|null $loyalty_tier_id
 * @property-read LoyaltyTier|null $loyaltyTier
 * @property LoyaltyRewardType $type
 * @property int $points_cost
 * @property string $currency
 * @property float|null $value
 * @property PriceType $value_type
 * @property int $max_redemptions_per_order
 * @property int|null $usage_limit
 * @property int|null $per_customer_limit
 * @property-read Media|null $icon
 * @property array|null $conditions
 * @property array|null $meta
 * @property int $total_redeemed
 * @property int $total_customers
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class LoyaltyReward extends Model
{
    use HasActiveStatus,
        HasActivityLog,
        HasCreatedBy,
        HasTagsCache,
        HasSortBy,
        HasFilters,
        HasFactory,
        HasOrder,
        SoftDeletes,
        HasActiveStatus,
        HasMedia,
        Translatable;

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
        "name",
        "description",
        "loyalty_program_id",
        "loyalty_tier_id",
        "points_cost",
        "type",
        "currency",
        "value",
        "value_type",
        "max_redemptions_per_order",
        "usage_limit",
        "per_customer_limit",
        "conditions",
        "meta",
        "total_redeemed",
        "total_customers",
        "starts_at",
        "ends_at",
        self::ACTIVE_COLUMN_NAME,
        self::ORDER_COLUMN_NAME
    ];
    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ["name", "description", "tier_name", "reward_name", "program_name"];

    /**
     * Get a list of all reward.
     *
     * @param int|null $programId
     * @return Collection
     */
    public static function list(?int $programId = null): Collection
    {
        return Cache::tags("loyalty_rewards")
            ->rememberForever(
                makeCacheKey(
                    [
                        'loyalty_rewards',
                        is_null($programId) ? 'all' : "program-{$programId}",
                        'list'
                    ],
                    false
                ),
                fn() => static::select('id', 'name')
                    ->when(!is_null($programId), fn($query) => $query->where("loyalty_program_id", $programId))
                    ->get()
                    ->map(fn(LoyaltyReward $loyaltyReward) => [
                        'id' => $loyaltyReward->id,
                        'name' => $loyaltyReward->name
                    ])
            );
    }

    protected static function newFactory(): LoyaltyRewardFactory
    {
        return LoyaltyRewardFactory::new();
    }

    /**
     * Get the reward value as Money or raw percent.
     *
     * @return Attribute<Money|float|int|null, never>
     */
    public function value(): Attribute
    {
        return Attribute::get(
            fn($value) => is_null($value) || $this->value_type === PriceType::Percent
                ? $value
                : Money::inDefaultCurrency($value)
        );
    }

    /**
     * Get currency
     *
     * @return Attribute<string, never>
     */
    public function currency(): Attribute
    {
        return Attribute::get(fn($currency) => $currency ?: setting("default_currency"));
    }

    /**
     * Get icon
     *
     * @return Attribute<Media|null>
     */
    public function icon(): Attribute
    {
        return Attribute::get(fn() => $this->relationLoaded('files') ? $this->files->where('pivot.zone', 'icon')->first() : null);
    }

    /**
     * Get loyalty program
     *
     * @return BelongsTo<LoyaltyProgram,static>
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
     * @return BelongsTo<LoyaltyTier,static>
     */
    public function loyaltyTier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class)
            ->withTrashed()
            ->withoutGlobalActive();
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            "type",
            "loyalty_program_id",
            "loyalty_tier_id",
            "group_by_date",
            self::ACTIVE_COLUMN_NAME,
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
        $query->whereLikeTranslation('name', $value);
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
        $query->where('loyalty_rewards.loyalty_program_id', $value);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            self::ACTIVE_COLUMN_NAME => "boolean",
            "points_cost" => "int",
            "type" => LoyaltyRewardType::class,
            "value_type" => PriceType::class,
            "max_redemptions_per_order" => "int",
            "usage_limit" => "int",
            "per_customer_limit" => "int",
            "conditions" => "array",
            "meta" => "array",
            "total_redeemed" => "int",
            "total_customers" => "int",
            "starts_at" => "datetime",
            "ends_at" => "datetime",
            "start_date" => "datetime",
            "end_date" => "datetime",
        ];
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
            "loyalty_program_id",
            "type",
            "points_cost",
            "total_redeemed",
            "total_customers",
            self::ACTIVE_COLUMN_NAME,
        ];
    }
}
