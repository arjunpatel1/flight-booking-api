<?php

namespace Modules\Menu\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Menu\Database\Factories\OnlineMenuFactory;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property string $name
 * @property int|null $menu_id
 * @property-read Menu|null $menu
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class OnlineMenu extends Model
{
    use HasActiveStatus,
        HasActivityLog,
        HasFactory,
        HasCreatedBy,
        HasTagsCache,
        HasSortBy,
        HasFilters,
        Translatable,
        HasBranch,
        SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "name",
        "menu_id",
        "slug",
        self::BRANCH_COLUMN_NAME,
        "hotel_branch_id",
        self::ACTIVE_COLUMN_NAME
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name'];

    /**
     * Find by Slug
     *
     * @param string $slug
     * @param array $with
     * @return self|null
     */
    public static function findBySlug(string $slug, array $with = []): ?self
    {
        return static::where('slug', $slug)
            ->with($with)
            ->withOutGlobalBranchPermission()
            ->first();
    }

    protected static function newFactory(): OnlineMenuFactory
    {
        return OnlineMenuFactory::new();
    }

    /**
     * Set Slug
     *
     * @return Attribute
     */
    public function slug(): Attribute
    {
        return Attribute::set(fn($slug) => str($slug)->slug()->toString());
    }

    /**
     * Get menu
     *
     * @return BelongsTo
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class)
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    /**
     * Get hotel branch
     *
     * @return BelongsTo
     */
    public function hotelBranch(): BelongsTo
    {
        return $this->belongsTo('Modules\Hotels\Models\Hotel', 'hotel_branch_id');
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            "menu_id",
            self::ACTIVE_COLUMN_NAME,
            self::BRANCH_COLUMN_NAME,
            "hotel_branch_id"
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

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
            "menu_id",
            "slug",
            self::ACTIVE_COLUMN_NAME,
            self::BRANCH_COLUMN_NAME,
            "hotel_branch_id"
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
        ];
    }
}
