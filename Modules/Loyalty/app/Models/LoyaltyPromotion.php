<?php

namespace Modules\Loyalty\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Loyalty\Database\Factories\LoyaltyPromotionFactory;
use Modules\Loyalty\Enums\LoyaltyPromotionType;
use Modules\Support\Eloquent\Model;
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
 * @property float|null $multiplier
 * @property int|null $bonus_points
 * @property LoyaltyPromotionType $type
 * @property int|null $usage_limit
 * @property int|null $per_customer_limit
 * @property int $total_used
 * @property int $total_customers
 * @property array|null $conditions
 * @property array|null $meta
 * @property float|int|null $value
 * @property string $value_text
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class LoyaltyPromotion extends Model
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
        "type",
        "loyalty_program_id",
        "multiplier",
        "bonus_points",
        "usage_limit",
        "per_customer_limit",
        "total_used",
        "total_customers",
        "conditions",
        "meta",
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
    protected array $translatable = ["name", "description"];

    protected static function newFactory(): LoyaltyPromotionFactory
    {
        return LoyaltyPromotionFactory::new();
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

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            "type",
            "loyalty_program_id",
            self::ACTIVE_COLUMN_NAME,
        ];
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
     * Get value type
     *
     * @return Attribute<int|float,never>
     */
    public function value(): Attribute
    {
        return Attribute::get(
            fn() => in_array(
                $this->type,
                [
                    LoyaltyPromotionType::BonusPoints,
                    LoyaltyPromotionType::NewMember,
                ])
                ? $this->bonus_points : $this->multiplier
        );
    }

    /**
     * Get value type
     *
     * @return Attribute<int|float,never>
     */
    public function valueText(): Attribute
    {
        return Attribute::get(
            fn() => in_array(
                $this->type,
                [
                    LoyaltyPromotionType::BonusPoints,
                    LoyaltyPromotionType::NewMember,
                ])
                ? "$this->bonus_points Pts" : "{$this->multiplier}X"
        );
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
        $query->where('loyalty_promotions.loyalty_program_id', $value);
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
            "type" => LoyaltyPromotionType::class,
            "usage_limit" => "int",
            "per_customer_limit" => "int",
            "conditions" => "array",
            "meta" => "array",
            "total_used" => "int",
            "total_customers" => "int",
            "starts_at" => "datetime",
            "ends_at" => "datetime",
            "multiplier" => 'float',
            "bonus_points" => "int",
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
            "multiplier",
            "bonus_points",
            "points_cost",
            "total_used",
            "total_customers",
            self::ACTIVE_COLUMN_NAME,
        ];
    }
}
