<?php

namespace Modules\Product\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranchCurrency;
use Modules\Category\Models\Category;
use Modules\Media\Models\Media;
use Modules\Media\Traits\HasMedia;
use Modules\Menu\Models\Menu;
use Modules\Menu\Traits\HasMenu;
use Modules\Option\Models\Option;
use Modules\Order\Models\OrderProduct;
use Modules\Product\Database\Factories\ProductFactory;
use Modules\Product\Enums\ProductFoodType;
use Modules\Product\Traits\HasSpecialPrice;
use Modules\Product\Traits\IsNew;
use Modules\Support\Eloquent\Model;
use Modules\Support\Enums\PriceType;
use Modules\Support\Money;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasUuid;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Tax\Models\Tax;
use Modules\Translation\Traits\Translatable;
use Znck\Eloquent\Traits\BelongsToThrough;

/**
 * @property int $id
 * @property string $sku
 * @property string $name
 * @property string $description
 * @property Collection<Tax> $taxes
 * @property-read  Tax $tax
 * @property Money $price
 * @property Money $selling_price
 * @property-read Media|null $thumbnail
 * @property-read string $currency
 * @property-read Branch $branch
 * @property-read Collection<Category> $categories
 * @property-read Collection<Ingredientable> $ingredients
 * @property-read Collection<Option> $options
 * @property-read Boolean @is_available
 * @property-read Boolean @is_recommended
 * @property-read Boolean @is_best_seller
 * @property int $display_priority
 * @property string $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Product extends Model
{
    use HasActiveStatus,
        HasActivityLog,
        HasFactory,
        HasCreatedBy,
        HasTagsCache,
        HasSortBy,
        HasFilters,
        HasUuid,
        HasMenu,
        IsNew,
        HasSpecialPrice,
        HasMedia,
        Translatable,
        HasBranchCurrency,
        BelongsToThrough,
        SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'description',
        'price',
        'special_price',
        'special_price_type',
        'special_price_start',
        'special_price_end',
        'new_from',
        'new_to',
        'sku',
        'hsn_code',
        'is_available',
        'is_recommended',
        'is_best_seller',
        'display_priority',
        'notes',
        'allergens',
        'dietary_labels',
        'food_type',
        self::ACTIVE_COLUMN_NAME,
        self::MENU_COLUMN_NAME,
        'image_original_path',
        'image_medium_path',
        'image_thumbnail_path',
        'image_original_size',
        'image_medium_size',
        'image_thumbnail_size',
        'image_optimized_at',
        'image_optimization_status',
        'image_optimization_error'
    ];

    /**
     * The relations to eager load on every query.
     *
     * @var array
     */
    protected $with = ["branch:branches.id,branches.currency"];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['thumbnail_url', 'medium_url', 'original_url'];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name', 'description'];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'price' => 'decimal:2',
        'special_price' => 'decimal:2',
        'special_price_start' => 'datetime',
        'special_price_end' => 'datetime',
        'new_from' => 'datetime',
        'new_to' => 'datetime',
        'is_available' => 'boolean',
        'is_recommended' => 'boolean',
        'is_best_seller' => 'boolean',
        'image_original_size' => 'integer',
        'image_medium_size' => 'integer',
        'image_thumbnail_size' => 'integer',
        'image_optimized_at' => 'datetime',
    ];

    /**
     * Get a list of all products group by sku.
     *
     * @return Collection
     */
    public static function listBySku(): Collection
    {
        return Cache::tags("products")
            ->rememberForever(
                makeCacheKey(['products', 'sku', 'list']),
                fn() => static::select('id', 'name', 'sku')
                    ->without(["branch"])
                    ->whereNotNull('sku')
                    ->groupBy('sku')
                    ->get()
                    ->map(fn(Product $product) => [
                        'id' => $product->sku,
                        'name' => $product->name
                    ])
            );
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saved(function (Product $product) {
            // Bridge the media picker to the optimizer so the POS is served a
            // small optimized thumbnail instead of the full-size original.
            \Modules\Product\Support\ProductThumbnailOptimizer::syncFromRequest($product);
        });

        static::updated(function ($product) {
            // Invalidate POS product cache when product is updated
            if (method_exists(Cache::getStore(), 'tags')) {
                Cache::tags(['products', 'categories'])->flush();
            }
        });

        static::deleted(function ($product) {
            // Invalidate POS product cache when product is deleted
            if (method_exists(Cache::getStore(), 'tags')) {
                Cache::tags(['products', 'categories'])->flush();
            }
        });

        static::restored(function ($product) {
            // Invalidate POS product cache when product is restored
            if (method_exists(Cache::getStore(), 'tags')) {
                Cache::tags(['products', 'categories'])->flush();
            }
        });
    }

    /**
     * Get the optimized thumbnail URL for POS pages.
     *
     * @return string|null
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        if ($this->image_thumbnail_path) {
            return $this->optimizedDisk()->url($this->image_thumbnail_path);
        }

        // Fallback to original image if thumbnail not available
        if (isset($this->attributes['image']) && $this->image) {
            return $this->optimizedDisk()->url($this->image);
        }

        return null;
    }

    /**
     * Disk that stores optimized image derivatives (must be web-servable).
     */
    protected function optimizedDisk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config('image.disk', 'public'));
    }

    /**
     * Get the optimized medium URL for product details pages.
     *
     * @return string|null
     */
    public function getMediumUrlAttribute(): ?string
    {
        if ($this->image_medium_path) {
            return $this->optimizedDisk()->url($this->image_medium_path);
        }

        // Fallback to original image if medium not available
        if (isset($this->attributes['image']) && $this->attributes['image']) {
            return $this->optimizedDisk()->url($this->attributes['image']);
        }

        return null;
    }

    /**
     * Get the optimized original URL.
     *
     * @return string|null
     */
    public function getOriginalUrlAttribute(): ?string
    {
        if ($this->image_original_path) {
            return $this->optimizedDisk()->url($this->image_original_path);
        }

        // Fallback to original image
        if (isset($this->attributes['image']) && $this->attributes['image']) {
            return $this->optimizedDisk()->url($this->attributes['image']);
        }

        return null;
    }

    /**
     * Check if images are optimized.
     *
     * @return bool
     */
    public function isImageOptimized(): bool
    {
        return $this->image_optimization_status === 'completed' &&
               $this->image_thumbnail_path &&
               $this->image_medium_path &&
               $this->image_original_path;
    }

    /**
     * Get total storage used by images.
     *
     * @return int
     */
    public function getTotalImageSize(): int
    {
        return ($this->image_thumbnail_size ?? 0) +
               ($this->image_medium_size ?? 0) +
               ($this->image_original_size ?? 0);
    }

    /**
     * Scope for products with optimized images.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeWithOptimizedImages(Builder $query): Builder
    {
        return $query->where('image_optimization_status', 'completed')
                   ->whereNotNull('image_thumbnail_path')
                   ->whereNotNull('image_medium_path');
    }

    /**
     * Scope for products needing image optimization.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeNeedsOptimization(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('image_optimization_status')
              ->orWhere('image_optimization_status', '!=', 'completed');
        });
    }

    /**
     * Get the categories associated with the product.
     *
     * @return BelongsToMany<Category>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_categories');
    }

    /**
     * Get the taxes configuration assigned to the product.
     *
     * @return BelongsToMany<Tax>
     */
    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'product_taxes')
            ->withOutGlobalBranchPermission();
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            'is_available',
            'is_recommended',
            'is_best_seller',
            'display_priority',
            "from",
            "to",
            self::ACTIVE_COLUMN_NAME,
            self::MENU_COLUMN_NAME,
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
        $query->where(fn(Builder $query) => $query->whereLikeTranslation('name', $value)->orLike('sku', $value));
    }

    /**
     * Get selling price
     *
     * @return Attribute<Money>
     */
    public function sellingPrice(): Attribute
    {
        return Attribute::get(function () {
            if (array_key_exists('pos_resolved_price', $this->getAttributes()) && ! is_null($this->getAttribute('pos_resolved_price'))) {
                return new Money((float) $this->getAttribute('pos_resolved_price'), $this->currency);
            }

            return $this->hasSpecialPrice() ? $this->getSpecialPrice() : $this->price;
        });
    }

    /**
     * Get product thumbnail
     *
     * @return Attribute<Media|null>
     */
    public function thumbnail(): Attribute
    {
        return Attribute::get(fn() => $this->relationLoaded('files') ? $this->files->where('pivot.zone', 'thumbnail')->first() : null);
    }

    /**
     * Get branch
     *
     * @return \Znck\Eloquent\Relations\BelongsToThrough
     */
    public function branch(): \Znck\Eloquent\Relations\BelongsToThrough
    {
        return $this->belongsToThrough(Branch::class, Menu::class);
    }

    /**
     * Get currency
     *
     * @return Attribute
     */
    public function currency(): Attribute
    {
        return Attribute::get(
            fn() => $this->relationLoaded("branch")
                ? ($this->branch?->currency ?: setting('default_currency'))
                : setting('default_currency')
        );
    }

    /**
     * Get price
     *
     * @return Attribute
     */
    public function price(): Attribute
    {
        return Attribute::get(function ($price) {
            if (array_key_exists('pos_resolved_price', $this->getAttributes()) && ! is_null($this->getAttribute('pos_resolved_price'))) {
                return new Money((float) $this->getAttribute('pos_resolved_price'), $this->currency);
            }

            return new Money($price, $this->currency);
        });
    }

    /**
     * Get options
     *
     * @return BelongsToMany
     */
    public function options(): BelongsToMany
    {
        return $this->belongsToMany(Option::class, 'product_options')
            ->orderBy('order')
            ->with('values')
            ->withTrashed();
    }

    /**
     * Has any option
     *
     * @return bool
     */
    public function hasAnyOption(): bool
    {
        return $this->getAttribute('options')->isNotEmpty();
    }

    /**
     * Get ingredients
     *
     * @return MorphMany
     */
    public function ingredients(): MorphMany
    {
        return $this->morphMany(Ingredientable::class, 'ingredientable')
            ->orderBy('order');
    }

    /**
     * Get prices by price type for this product.
     *
     * @return HasMany
     */
    public function productPrices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * Get order lines for sales analytics.
     *
     * @return HasMany
     */
    public function orderProducts(): HasMany
    {
        return $this->hasMany(OrderProduct::class);
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
            "price",
            "display_priority",
            "is_recommended",
            "is_best_seller",
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
            'special_price_type' => PriceType::class,
            'new_from' => "datetime",
            'new_to' => "datetime",
            'special_price_start' => "datetime",
            'special_price_end' => "datetime",
            'is_available' => "boolean",
            'is_recommended' => "boolean",
            'is_best_seller' => "boolean",
            'display_priority' => "integer",
            'allergens' => "array",
            'dietary_labels' => "array",
            'food_type' => ProductFoodType::class,
            self::ACTIVE_COLUMN_NAME => "boolean",
        ];
    }
}
