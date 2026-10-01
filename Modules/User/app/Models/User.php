<?php

namespace Modules\User\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Illuminate\Notifications\HasDatabaseNotifications;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\HasApiTokens;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Models\Branch;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderFeedback;
use Modules\Branch\Traits\HasBranch;
use Modules\Printer\Models\Printer;
use Modules\Saas\Models\Tenant;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Media\Traits\HasMedia;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasProfilePhoto;
use Modules\Support\Traits\HasUuid;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\User\Database\Factories\UserFactory;
use Modules\User\Enums\DefaultRole;
use Modules\User\Enums\GenderType;
use Modules\User\Traits\HasRoles;
use Yadahan\AuthenticationLog\AuthenticationLogable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $username
 * @property string|null $email
 * @property GenderType|null $gender
 * @property string|null $password
 * @property string|null $phone_country_iso_code
 * @property string|null $phone
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property string|null $national_phone
 * @property Carbon|null $date_of_birth
 * @property Carbon|null $anniversary_date
 * @property-read Branch|null $effective_branch
 * @property array|null $category_slugs
 * @property array|null $order_types
 * @property int|null $printer_id
 * @property-read  Printer|null $printer
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class User extends Authenticatable implements PasskeyUser
{
    use
        AuthenticationLogable,
        PasskeyAuthenticatable,
        HasActiveStatus,
        HasActivityLog,
        HasApiTokens,
        HasUuid,
        HasDatabaseNotifications,
        HasFactory,
        HasProfilePhoto,
        HasRoles,
        HasCreatedBy,
        HasBranch,
        HasTagsCache,
        HasSortBy,
        HasFilters,
        HasMedia,
        Notifiable,
        SoftDeletes;


    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'email_verified_at',
        'password',
        'pos_pin_hash',
        'gender',
        'date_of_birth',
        'anniversary_date',
        'whatsapp_marketing_consent',
        'whatsapp_marketing_consented_at',
        'whatsapp_consent_source',
        'whatsapp_opted_out_at',
        'phone_country_iso_code',
        'phone',
        'phone_verified_at',
        'category_slugs',
        'order_types',
        'printer_id',
        'tenant_id',
        'mfa_enabled',
        'mfa_secret',
        'mfa_recovery_codes',
        'mfa_confirmed_at',
        'can_login',
        self::BRANCH_COLUMN_NAME,
        self::ACTIVE_COLUMN_NAME,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'pos_pin_hash',
        'remember_token',
        'mfa_secret',
        'mfa_recovery_codes',
    ];

    /**
     * The relations to eager load on every query.
     *
     * @var array
     */
    protected $with = ["roles"];

    protected $casts = [
        'date_of_birth' => 'date',
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'anniversary_date' => 'date',
        'whatsapp_marketing_consent' => 'boolean',
        'whatsapp_marketing_consented_at' => 'datetime',
        'whatsapp_opted_out_at' => 'datetime',
        'mfa_enabled' => 'boolean',
        'mfa_secret' => 'encrypted',
        'mfa_recovery_codes' => 'encrypted:array',
        'mfa_confirmed_at' => 'datetime',
        'order_types' => 'array',
    ];

    /**
     * Get a list of all suppliers.
     *
     * @param int|null $branchId
     * @param DefaultRole|null $defaultRole
     * @return Collection
     */
    public static function list(?int $branchId = null, ?DefaultRole $defaultRole = null): Collection
    {
        $tenantId = app(\Modules\Saas\Support\TenantContext::class)->id()
            ?? (auth()->user()?->getAttributes()['tenant_id'] ?? null);
        return Cache::tags("users")
            ->rememberForever(
                makeCacheKey(
                    [
                        'users',
                        is_null($tenantId) ? 'platform' : "tenant-$tenantId",
                        is_null($branchId) ? 'all' : "branch-$branchId",
                        is_null($defaultRole) ? 'all' : "default-role-$defaultRole->value",
                        'list'
                    ],
                    false
                ),
                fn() => static::select('id', 'name', 'phone', 'phone_country_iso_code')
                    ->when($tenantId, fn($query) => $query->where('tenant_id', $tenantId))
                    ->when(
                        !is_null($branchId),
                        fn($query) => $query->where(fn($branchQuery) => $branchQuery
                            ->whereBranch($branchId)->orWhereNull('branch_id'))
                    )
                    ->when(
                        !is_null($defaultRole),
                        fn($query) => $query->role($defaultRole->value)
                    )
                    ->get()
                    ->map(fn(User $user) => [
                        'id' => $user->id,
                        // Imported numbers may not parse as international phone numbers.
                        'name' => "$user->name" . (blank($user->phone) ? '' : " (" . preg_replace('/\s+/', '', (string) $user->phone) . ")")
                    ])
            );
    }

    /**
     * Get walk-in Name
     * @return string
     */
    public static function walkInName(): string
    {
        return "Walk-In Customer";
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(OrderFeedback::class, 'customer_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Boot the model and handle stock adjustments on create, update, and delete.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->isDirty('whatsapp_marketing_consent')) {
                if ($user->whatsapp_marketing_consent) {
                    $user->whatsapp_marketing_consented_at ??= now();
                    $user->whatsapp_opted_out_at = null;
                } elseif ($user->exists && (bool) $user->getOriginal('whatsapp_marketing_consent')) {
                    // An opt-out is an explicit withdrawal of prior consent.
                    // Do not label a new customer who never opted in as having
                    // opted out: those are different compliance states.
                    $user->whatsapp_opted_out_at = now();
                }
            }
        });

        static::creating(function (User $user) {
            $actor = auth()->user();

            // Tenant portals never accept ownership from the browser. Stamp it
            // from the authenticated actor so users created from Users & Roles
            // remain visible and can authenticate on the restaurant domain.
            if (blank($user->tenant_id) && $actor?->assignedToTenant() && ! $actor->isSuperAdmin()) {
                $user->tenant_id = $actor->tenantId();
            }

            // Branch ownership is also sufficient to derive the tenant for
            // imports and other service paths that do not carry tenant_id.
            if (blank($user->tenant_id) && filled($user->branch_id)) {
                $user->tenant_id = Branch::query()
                    ->withoutGlobalScopes()
                    ->whereKey($user->branch_id)
                    ->value('tenant_id');
            }
        });

        static::saving(function (User $user) {
            if (!$user->hasRole(DefaultRole::Kitchen->value)) {
                $user->printer_id = null;
                $user->category_slugs = null;
            }
        });
    }

    /**
     * Create a new instance of the factory for generating AcademicYear models.
     *
     * @return UserFactory
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            "role",
            "gender",
            "can_login",
            self::ACTIVE_COLUMN_NAME,
            self::BRANCH_COLUMN_NAME
        ];
    }

    /**
     * Determine if the main user
     * @return bool
     */
    public function isMainUser(): bool
    {
        return $this->id === 1;
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
        $query->where(function ($query) use ($value) {
            $query->like('name', $value)
                ->orLike('username', $value)
                ->orLike('phone', $value)
                ->orLike('email', $value);
        });

    }

    /**
     * Get effective branch
     *
     * @return Attribute
     */
    public function effectiveBranch(): Attribute
    {
        return Attribute::get(fn() => $this->assignedToBranch() ? $this->branch : Branch::main()->first());
    }

    /**
     * Determine if user assigned to branch or not
     *
     * @return bool
     */
    public function assignedToBranch(): bool
    {
        return $this->branchId() !== null;
    }

    public function assignedToTenant(): bool
    {
        return $this->tenantId() !== null;
    }

    public function tenantId(): ?int
    {
        $value = $this->getAttributes()['tenant_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function branchId(): ?int
    {
        $value = $this->getAttributes()[self::BRANCH_COLUMN_NAME] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Phone attribute
     *
     * @return Attribute
     */
    public function phone(): Attribute
    {
        return Attribute::make(
            get: fn($phone) => !is_null($phone) && !empty($this->phone_country_iso_code)
                ? phone($phone, $this->phone_country_iso_code)->formatInternational()
                : $phone,
        );
    }

    /**
     * National mobile number attribute
     *
     * @return Attribute
     */
    public function nationalPhone(): Attribute
    {
        return Attribute::get(
            fn() => !is_null($this->getAttributes()['phone']) && !empty($this->phone_country_iso_code)
                ? phone($this->getAttributes()['phone'], $this->phone_country_iso_code)->formatNational()
                : $this->getAttributes()['phone']
        );
    }

    /**
     * The profile image picked from the media library (files pivot, zone "avatar").
     * Lazily resolves the file so it works whether or not "files" is eager-loaded.
     *
     * @return Attribute
     */
    public function profilePhoto(): Attribute
    {
        return Attribute::get(fn() => $this->relationLoaded('files')
            ? $this->files->firstWhere('pivot.zone', 'avatar')
            : $this->files()->wherePivot('zone', 'avatar')->first());
    }

    /**
     * Override HasProfilePhoto: return the picked avatar, else the generated default.
     *
     * @return Attribute
     */
    public function profilePhotoUrl(): Attribute
    {
        return Attribute::get(fn() => $this->profile_photo?->preview_image_url
            ?? $this->defaultProfilePhotoUrl(
                $this->defaultProfilePhotoColor(),
                $this->defaultProfilePhotoBackgroundColor()
            ));
    }

    /**
     * Get printer
     *
     * @return BelongsTo
     */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
            "email",
            "username",
            "gender",
            "phone"
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
            'password' => 'hashed',
            'gender' => GenderType::class,
            'date_of_birth' => 'date',
            'is_active' => 'boolean',
            'can_login' => 'boolean',
            'category_slugs' => 'array',
            'tenant_id' => 'integer',
        ];
    }
}
