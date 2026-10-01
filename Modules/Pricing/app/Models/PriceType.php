<?php

namespace Modules\Pricing\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Pricing\Enums\PriceTypeRuleType;
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
 * @property string $code
 * @property PriceTypeRuleType $rule_type
 * @property float $rule_value
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class PriceType extends Model
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
        'code',
        'rule_type',
        'rule_value',
        'description',
        self::ACTIVE_COLUMN_NAME,
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name', 'description'];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'rule_type' => PriceTypeRuleType::class,
        'rule_value' => 'float',
        self::ACTIVE_COLUMN_NAME => 'boolean',
    ];

    /**
     * Return active price types for selects.
     */
    public static function list(): Collection
    {
        return Cache::tags('price_types')
            ->rememberForever('price_types_list', fn() => static::query()
                ->select('id', 'name', 'code', 'rule_type', 'rule_value')
                ->active()
                ->latest()
                ->get()
                ->map(fn(PriceType $priceType) => [
                    'id' => $priceType->id,
                    'name' => $priceType->name,
                    'code' => $priceType->code,
                    'rule_type' => $priceType->rule_type->toTrans(),
                    'rule_value' => $priceType->rule_value,
                ]));
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::creating(function (PriceType $priceType) {
            if (blank($priceType->code)) {
                $priceType->code = static::generateCode($priceType->name);
            }
        });
    }

    /**
     * Generate a stable readable code.
     */
    public static function generateCode(mixed $name): string
    {
        $text = is_array($name) ? ($name[app()->getLocale()] ?? reset($name) ?: 'price-type') : (string) $name;
        $base = Str::upper(Str::slug($text, '_')) ?: 'PRICE_TYPE';
        $code = $base;
        $counter = 1;

        while (static::withTrashed()->where('code', $code)->exists()) {
            $counter++;
            $code = "{$base}_{$counter}";
        }

        return $code;
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            'search',
            'rule_type',
            'from',
            'to',
            self::ACTIVE_COLUMN_NAME,
        ];
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            'name',
            'code',
            'rule_type',
            'rule_value',
            self::ACTIVE_COLUMN_NAME,
            self::CREATED_BY_COLUMN_NAME,
        ];
    }

    /**
     * Scope a query to search across useful fields.
     */
    public function scopeSearch(Builder $query, string $value): void
    {
        $query->where(function (Builder $query) use ($value) {
            $query->whereLikeTranslation('name', $value)
                ->orWhereLikeTranslation('description', $value)
                ->orLike('code', $value);
        });
    }
}
