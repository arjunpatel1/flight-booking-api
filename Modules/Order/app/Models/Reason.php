<?php

namespace Modules\Order\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Order\Enums\ReasonType;
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
 * @property ReasonType $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Reason extends Model
{
    use SoftDeletes,
        HasTagsCache,
        HasCreatedBy,
        HasActiveStatus,
        Translatable,
        HasSortBy,
        HasFilters,
        HasActivityLog;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'type',
        self::ACTIVE_COLUMN_NAME,
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name'];

    /**
     * Get a list of all cancellation reasons.
     *
     * @param string|null $type
     * @return Collection
     */
    public static function list(?string $type = null): Collection
    {
        return Cache::tags("reasons")
            ->rememberForever(
                makeCacheKey([
                    'reasons',
                    is_null($type) ? 'all' : "type-{$type}",
                    'list'
                ]),
                fn() => static::select('id', 'name')
                    ->when(!is_null($type), fn($query) => $query->where("type", $type))
                    ->get()
                    ->map(fn(Reason $reason) => [
                        'id' => $reason->id,
                        'name' => $reason->name,
                    ])
            );
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
            self::ACTIVE_COLUMN_NAME
        ];
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
            "type",
            self::ACTIVE_COLUMN_NAME,
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
            self::ACTIVE_COLUMN_NAME => "bool",
            "type" => ReasonType::class,
        ];
    }
}
