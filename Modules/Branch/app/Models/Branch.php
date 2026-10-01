<?php

namespace Modules\Branch\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Database\Factories\BranchFactory;
use Modules\Media\Traits\HasMedia;
use Modules\Menu\Models\OnlineMenu;
use Modules\Order\Enums\OrderType;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Saas\Models\Tenant;
use Modules\Support\Country;
use Modules\Support\Eloquent\Model;
use Modules\Support\Money;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasUuid;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property string $name
 * @property string $legal_name
 * @property string|null $registration_number
 * @property string|null $vat_tin
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postal_code
 * @property string|null $phone
 * @property string|null $email
 * @property string $country_code
 * @property string $timezone
 * @property string $currency
 * @property float|null $latitude
 * @property float|null $longitude
 * @property float|null $delivery_radius_km
 * @property float|null $delivery_minimum_order
 * @property array|null $cuisines
 * @property array|null $opening_hours
 * @property int|null $delivery_eta_minutes
 * @property float|null $price_for_two
 * @property bool $is_accepting_orders
 * @property Money $cash_difference_threshold
 * @property bool $is_main
 * @property OrderType[] $order_types
 * @property PaymentMethod[] $payment_methods
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * @method static Builder|self main(bool $operator = true)
 * @method static Builder|self notMain()
 *
 * @mixin Builder
 */
class Branch extends Model
{
    use HasFactory,
        HasUuid,
        SoftDeletes,
        HasTagsCache,
        HasCreatedBy,
        HasActiveStatus,
        Translatable,
        HasSortBy,
        HasFilters,
        HasActivityLog,
        HasMedia;

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name'];

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'tenant_id',
        'legal_name',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'phone',
        'email',
        'country_code',
        'cash_difference_threshold',
        'timezone',
        'currency',
        'latitude',
        'longitude',
        'delivery_radius_km',
        'delivery_assignment_timeout_minutes',
        'delivery_minimum_order',
        'cuisines',
        'opening_hours',
        'delivery_eta_minutes',
        'price_for_two',
        'is_accepting_orders',
        'is_main',
        'registration_number',
        'vat_tin',
        'postal_code',
        'order_types',
        'payment_methods',
        'quick_pay_amounts',
        'hide_waiter_selection',
        'appearance_settings',
        self::ACTIVE_COLUMN_NAME,
    ];

    protected static function booted(): void
    {
        // Resolve the authenticated user when the query executes, not when the
        // model class first boots. Long-running workers may boot this model
        // before authentication and would otherwise keep an unscoped model.
        static::addGlobalScope('tenant_permission', function (Builder $builder) {
            if (! auth()->check()) {
                return;
            }

            $user = auth()->user();
            if ($user?->assignedToTenant() && ! $user->isSuperAdmin()) {
                $builder->where("{$builder->getModel()->getTable()}.tenant_id", $user->tenant_id);
            }
        });
    }


    /**
     * Get a list of all roles.
     *
     * @return Collection
     */
    public static function list(): Collection
    {
        return Cache::tags("branches")
            ->rememberForever(makeCacheKey(['branches', 'list']),
                fn() => static::select('id', 'name', 'currency')
                    ->orderBy('is_main', "desc")
                    ->latest()
                    ->get()
                    ->map(fn(Branch $branch) => [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        "currency" => $branch->currency,
                    ])
            );
    }

    /**
     * Get branch online menus.
     */
    public function onlineMenus(): HasMany
    {
        return $this->hasMany(OnlineMenu::class)
            ->withoutGlobalActive()
            ->withOutGlobalBranchPermission();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }

    /**
     * Scope a query to where main.
     *
     * @param Builder $query
     * @param bool $operator
     * @return void
     * @noinspection PhpUnused
     */
    public function scopeMain(Builder $query, bool $operator = true): void
    {
        $query->where('is_main', $operator);
    }

    /**
     * Scope a query to where not Main.
     *
     * @param Builder $query
     * @return void
     * @noinspection PhpUnused
     */
    public function scopeNotMain(Builder $query): void
    {
        /** @var self $query */
        $query->main(false);
    }

    /**
     * Determine if a role is a main
     *
     * @return bool
     */
    public function isMain(): bool
    {
        return (bool)$this->is_main;
    }

    /**
     * Get country name
     *
     * @return string
     */
    public function getCountryName(): string
    {
        return Country::name($this->country_code);
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "country_code",
            "currency",
            "from",
            "to",
            "is_active",
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
                ->orLike('phone', $value)
                ->orLike('email', $value);
        });
    }

    /**
     * Get cash difference threshold
     *
     * @return Attribute
     */
    public function cashDifferenceThreshold(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }

    /**
     * Get the branch cover photo shown on customer app restaurant cards.
     *
     * @return Attribute
     */
    public function cover(): Attribute
    {
        return Attribute::get(fn() => $this->relationLoaded('files')
            ? $this->files->where('pivot.zone', 'cover')->first()
            : null);
    }

    /**
     * Get the branch logo. Falls back to the tenant logo at the presenter level
     * when an outlet has not uploaded its own.
     *
     * @return Attribute
     */
    public function logo(): Attribute
    {
        return Attribute::get(fn() => $this->relationLoaded('files')
            ? $this->files->where('pivot.zone', 'logo')->first()
            : null);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'order_types' => 'array',
            'payment_methods' => 'array',
            'quick_pay_amounts' => 'array',
            'appearance_settings' => 'array',
            'hide_waiter_selection' => 'boolean',
            'delivery_radius_km' => 'float',
            'delivery_assignment_timeout_minutes' => 'integer',
            'delivery_minimum_order' => 'float',
            'cuisines' => 'array',
            'opening_hours' => 'array',
            'delivery_eta_minutes' => 'integer',
            'price_for_two' => 'float',
            'is_accepting_orders' => 'boolean',
        ];
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return ["name"];
    }
}
