<?php

namespace Modules\Tax\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Order\Enums\OrderType;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Tax\Enums\GstType;
use Modules\Tax\Enums\TaxType;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property float $rate
 * @property TaxType $type
 * @property bool $compound
 * @property bool $is_active
 * @property bool $is_global
 * @property OrderType[] $order_types
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * @method static Builder|static global()
 * @method static Builder|static nonGlobal()
 */
class Tax extends Model
{
    use SoftDeletes,
        HasTagsCache,
        HasCreatedBy,
        HasActiveStatus,
        Translatable,
        HasSortBy,
        HasBranch,
        HasFilters,
        HasActivityLog;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "name",
        "code",
        "rate",
        "type",
        "gst_type",
        "compound",
        "is_global",
        "order_types",
        "is_global",
        self::ACTIVE_COLUMN_NAME,
        self::BRANCH_COLUMN_NAME
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name'];

    /**
     * The relations to eager load on every query.
     *
     * @var array
     */
    protected $with = ["branch"];

    /**
     * Get a list of all roles.
     *
     * @param int|null $branchId
     * @param bool|null $isGlobal
     * @return Collection
     */
    public static function list(?int $branchId = null, ?bool $isGlobal = null): Collection
    {
        return Cache::tags("taxes")
            ->rememberForever(
                makeCacheKey([
                    'taxes',
                    is_null($branchId) ? 'all' : "branch-$branchId",
                    is_null($isGlobal) ? 'all' : "global-$isGlobal",
                    'list'
                ]),
                function () use ($branchId, $isGlobal) {
                    $taxes = static::select(
                        'id',
                        'name',
                        'code',
                        'rate',
                        'type',
                        'gst_type',
                        'compound',
                        'branch_id',
                        'order_types',
                    )
                        ->latest()
                        ->when($branchId !== null, fn($q) => $q
                            ->withOutGlobalBranchPermission()
                            ->where('branch_id', $branchId))
                        ->when($isGlobal === true, fn($q) => $q->global())
                        ->when($isGlobal === false, fn($q) => $q->nonGlobal())
                        ->get();

                    if ($branchId) {
                        $codes = [];
                        $taxes = $taxes
                            ->sortByDesc('branch_id')
                            ->filter(function ($tax) use (&$codes) {
                                if (in_array($tax->code, $codes)) return false;
                                $codes[] = $tax->code;
                                return true;
                            })
                            ->values();
                    }

                    return $taxes->map(fn(Tax $tax) => [
                        'id' => $tax->id,
                        'code' => $tax->code,
                        'name' => $tax->name,
                        'rate' => $tax->rate,
                        'type' => $tax->type,
                        'gst_type' => $tax->gst_type,
                        'compound' => $tax->compound,
                        'order_types' => $tax->order_types ?: [],
                    ]);
                }
            );
    }

    /**
     * Boot the model and handle stock adjustments on create, update, and delete.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::saving(function (Tax $tax) {
            if ($tax->isInclusive()) {
                $tax->order_types = [];
                $tax->compound = false;
            }
        });
    }

    /**
     * Check if the tax is inclusive.
     *
     * @return bool
     */
    public function isInclusive(): bool
    {
        return $this->type === TaxType::Inclusive;
    }

    /**
     * Scope a query to only include global taxes.
     *
     * @param Builder $query
     * @return void
     * @noinspection PhpUnused
     */
    public function scopeGlobal(Builder $query): void
    {
        $query->where('is_global', true);
    }

    /**
     * Scope a query to only include non-global taxes.
     *
     * @param Builder $query
     * @return void
     * @noinspection PhpUnused
     */
    public function scopeNonGlobal(Builder $query): void
    {
        $query->where('is_global', false);
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "type",
            "from",
            "to",
            self::ACTIVE_COLUMN_NAME,
            self::BRANCH_COLUMN_NAME
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
        $query->where(function (Builder $query) use ($value) {
            $query->whereLikeTranslation('name', $value)
                ->orLike('code', $value);
        });
    }

    /**
     * Check if the tax is exclusive.
     *
     * @return bool
     */
    public function isExclusive(): bool
    {
        return $this->type === TaxType::Exclusive;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'compound' => 'boolean',
            'is_active' => 'boolean',
            "is_global" => "boolean",
            'order_types' => "array",
            "type"     => TaxType::class,
            "gst_type" => GstType::class,
        ];
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
            "code",
            "rate",
            "type",
            "compound",
            "is_global",
            self::ACTIVE_COLUMN_NAME,
            self::BRANCH_COLUMN_NAME,
        ];
    }
}
