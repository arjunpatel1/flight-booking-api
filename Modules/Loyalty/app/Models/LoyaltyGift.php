<?php

namespace Modules\Loyalty\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Discount\Models\Discount;
use Modules\Loyalty\Enums\LoyaltyGiftStatus;
use Modules\Loyalty\Enums\LoyaltyRewardType;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Translation\Traits\Translatable;
use Modules\Voucher\Models\Voucher;

/**
 * @property int $id
 * @property int $loyalty_customer_id
 * @property-read LoyaltyCustomer $customer
 * @property int $loyalty_reward_id
 * @property-read LoyaltyReward $reward
 * @property int|null $artifact_id
 * @property string|null $artifact_type
 * @property-read Voucher|Discount|null $artifact
 * @property int $loyalty_program_id
 * @property-read LoyaltyProgram $program
 * @property LoyaltyRewardType $type
 * @property LoyaltyGiftStatus $status
 * @property int $qty
 * @property int $points_spent
 * @property array $conditions
 * @property array $meta
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property Carbon|null $used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class LoyaltyGift extends Model
{
    use SoftDeletes, HasFilters, HasSortBy, Translatable;

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
    protected array $translatable = ['reward_name', 'program_name'];

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "loyalty_customer_id",
        "loyalty_program_id",
        "loyalty_reward_id",
        "artifact_id",
        "artifact_type",
        "type",
        "status",
        "qty",
        "points_spent",
        "valid_from",
        "valid_until",
        "used_at",
        "conditions",
        "meta",
    ];

    /**
     * Get artifact
     *
     * @return MorphTo
     */
    public function artifact(): MorphTo
    {
        return $this->morphTo()
            ->withTrashed()
            ->withoutGlobalActive();
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
     * Get loyalty reward
     *
     * @return BelongsTo
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class, 'loyalty_reward_id')
            ->withTrashed()
            ->withoutGlobalActive();
    }

    /**
     * Get loyalty program
     *
     * @return BelongsTo
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id')
            ->withTrashed()
            ->withoutGlobalActive();
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "status",
            "customer_id",
            "loyalty_program_id",
            "loyalty_reward_id",
            "group_by_date",
            "from",
            "to",
        ];
    }

    /**
     * Scope a query to get all gift by customer id.
     *
     * @param Builder $query
     * @param int $customerId
     * @return void
     */
    public function scopeCustomerId(Builder $query, int $customerId): void
    {
        $query->whereHas("customer", fn($query) => $query->where("customer_id", $customerId));
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
        $query->where('loyalty_gifts.loyalty_program_id', $value);
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
            'conditions' => 'array',
            "type" => LoyaltyRewardType::class,
            "status" => LoyaltyGiftStatus::class,
            "valid_from" => "datetime",
            "valid_until" => "datetime",
            "used_at" => "datetime",
            "start_date" => "datetime",
            "end_date" => "datetime",
        ];
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "loyalty_program_id",
            "loyalty_reward_id",
            "status",
            "type",
            "points_spent",
            "valid_until",
            "used_at"
        ];
    }
}
