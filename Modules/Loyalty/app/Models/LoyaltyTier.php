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
use Modules\Loyalty\Database\Factories\LoyaltyTierFactory;
use Modules\Media\Models\Media;
use Modules\Media\Traits\HasMedia;
use Modules\Support\Eloquent\Model;
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
 * @property string $benefits
 * @property int $loyalty_program_id
 * @property-read LoyaltyProgram $loyaltyProgram
 * @property Money $min_spend
 * @property float $multiplier
 * @property-read Media|null $icon
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class LoyaltyTier extends Model
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
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "name",
        "loyalty_program_id",
        "min_spend",
        "multiplier",
        "benefits",
        self::ACTIVE_COLUMN_NAME,
        self::ORDER_COLUMN_NAME
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ["name", "benefits"];

    /**
     * Get a list of all tiers.
     *
     * @param int|null $programId
     * @return Collection
     */
    public static function list(?int $programId = null): Collection
    {
        return Cache::tags("loyalty_tiers")
            ->rememberForever(
                makeCacheKey(
                    [
                        'loyalty_tiers',
                        is_null($programId) ? 'all' : "program-{$programId}",
                        'list'
                    ],
                    false
                ),
                fn() => static::select('id', 'name')
                    ->when(!is_null($programId), fn($query) => $query->where("loyalty_program_id", $programId))
                    ->get()
                    ->map(fn(LoyaltyTier $loyaltyTier) => [
                        'id' => $loyaltyTier->id,
                        'name' => $loyaltyTier->name
                    ])
            );
    }

    protected static function newFactory(): LoyaltyTierFactory
    {
        return LoyaltyTierFactory::new();
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "loyalty_program_id",
            "from",
            "to",
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
     * Get min spend
     *
     * @return Attribute
     */
    public function minSpend(): Attribute
    {
        return Attribute::get(fn($minSpend) => Money::inDefaultCurrency($minSpend));
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
     * @return BelongsTo
     */
    public function loyaltyProgram(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class)
            ->withTrashed()
            ->withoutGlobalActive();
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
            "loyalty_program_id",
            "min_spend",
            "multiplier",
            self::ACTIVE_COLUMN_NAME
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
            self::ACTIVE_COLUMN_NAME => "boolean",
            "multiplier" => "float",
        ];
    }
}
