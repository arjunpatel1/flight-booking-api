<?php

namespace Modules\Setting\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Modules\Setting\Events\SettingSaved;
use Modules\Saas\Support\TenantContext;
use Modules\Support\Eloquent\Model;
use Throwable;

class Setting extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['tenant_id', 'branch_id', 'setting_scope', 'key', 'is_translatable', 'is_encryptable', 'payload'];

    /**
     * The event map for the model.
     *
     * @var array
     */
    protected $dispatchesEvents = [
        'saved' => SettingSaved::class,
    ];

    /**
     * Get all settings with cache support.
     *
     * @return Collection
     */
    public static function allCached(): Collection
    {
        try {
            return Cache::tags('settings')->rememberForever(
                makeCacheKey(['settings', 'list', static::activeScopeKey()]),
                fn() => self::scopedQuery()->get()->mapWithKeys(function ($setting) {
                    return [$setting->key => $setting->payload];
                })
            );
        } catch (QueryException) {
            return collect();
        }
    }

    /**
     * Determine if the given setting key exists.
     *
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return static::scopedQuery()->where('key', $key)->exists();
    }

    /**
     * Set the given settings.
     *
     * @param array $settings
     * @return void
     */
    public static function setMany(array $settings): void
    {
        foreach ($settings as $key => $payload) {
            self::set($key, $payload);
        }
    }

    /**
     * Set the given setting.
     *
     * @param string $key
     * @param mixed $payload
     * @return void
     */
    public static function set(string $key, mixed $payload): void
    {
        if ($key === 'encryptable') {
            static::setEncryptableSettings($payload);
        } elseif ($key === 'translatable') {
            static::setTranslatableSettings($payload);
        } else {
            static::updateOrCreate(static::scopeAttributes($key), [
                'payload' => $payload,
                ...static::scopePayload(),
            ]);
        }
    }

    /**
     * Set an encryptable settings.
     *
     * @param array $settings
     * @return void
     */
    public static function setEncryptableSettings(array $settings = []): void
    {
        foreach ($settings as $key => $payload) {
            if ($key === 'translatable') {
                static::setTranslatableSettings($payload, true);
            } else {
                static::updateOrCreate(static::scopeAttributes($key), [
                    'is_encryptable' => true,
                    'payload' => $payload,
                    ...static::scopePayload(),
                ]);
            }
        }
    }

    /**
     * Set a translatable settings.
     *
     * @param array $settings
     * @param bool $encryptable
     *
     * @return void
     */
    public static function setTranslatableSettings(array $settings = [], bool $encryptable = false): void
    {
        foreach ($settings as $key => $payload) {
            $oldSetting = static::scopedQuery()->where('key', $key)->get()->last();
            $oldPayload = $oldSetting ? unserialize($oldSetting->getRawOriginal('payload')) : null;
            $oldPayload = is_array($oldPayload) ? $oldPayload : [];
            static::updateOrCreate(static::scopeAttributes($key), [
                'is_translatable' => true,
                'is_encryptable' => $encryptable,
                'payload' => array_merge($oldPayload, [locale() => $payload]),
                ...static::scopePayload(),
            ]);
        }
    }

    /**
     * Get setting for the given key.
     *
     * @param string $key
     * @param mixed $default
     * @return string|array|null
     */
    public static function get(string $key, mixed $default = null): string|array|null
    {
        try {
            return static::scopedQuery()->where('key', $key)->get()->last()?->payload ?: $default;
        } catch (QueryException) {
            return $default;
        }
    }

    private static function scopedQuery()
    {
        $query = static::query();

        if (! static::hasScopeColumns()) {
            return $query;
        }

        $scopeKeys = array_values(array_unique(array_filter([
            'global',
            static::tenantScopeKey(),
            static::branchScopeKey(),
        ])));

        return $query
            ->whereIn('setting_scope', $scopeKeys)
            ->orderByRaw("CASE WHEN setting_scope = 'global' THEN 0 WHEN setting_scope LIKE 'tenant:%' THEN 1 ELSE 2 END");
    }

    private static function scopeAttributes(string $key): array
    {
        if (! static::hasScopeColumns()) {
            return ['key' => $key];
        }

        return [
            'setting_scope' => static::activeScopeKey(),
            'key' => $key,
        ];
    }

    private static function scopePayload(): array
    {
        if (! static::hasScopeColumns()) {
            return [];
        }

        $tenantId = static::resolvedTenantId();
        $branchId = static::resolvedBranchId();

        return [
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'setting_scope' => static::scopeKey($tenantId, $branchId),
        ];
    }

    private static function activeScopeKey(): string
    {
        if (! static::hasScopeColumns()) {
            return 'legacy';
        }

        return static::scopeKey(static::resolvedTenantId(), static::resolvedBranchId());
    }

    private static function tenantScopeKey(): ?string
    {
        $tenantId = static::resolvedTenantId();

        return $tenantId ? "tenant:{$tenantId}" : null;
    }

    private static function branchScopeKey(): ?string
    {
        $tenantId = static::resolvedTenantId();
        $branchId = static::resolvedBranchId();

        return $tenantId && $branchId ? "branch:{$tenantId}:{$branchId}" : null;
    }

    private static function scopeKey(?int $tenantId, ?int $branchId): string
    {
        if ($tenantId && $branchId) {
            return "branch:{$tenantId}:{$branchId}";
        }

        if ($tenantId) {
            return "tenant:{$tenantId}";
        }

        return 'global';
    }

    private static function resolvedTenantId(): ?int
    {
        $tenantId = app()->bound(TenantContext::class)
            ? app(TenantContext::class)->id()
            : null;

        $user = auth()->user();

        return $tenantId ?: ($user && method_exists($user, 'tenantId') ? $user->tenantId() : null);
    }

    private static function resolvedBranchId(): ?int
    {
        return null;
    }

    private static function hasScopeColumns(): bool
    {
        try {
            return Schema::hasColumn('settings', 'setting_scope');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Set the payload of the setting.
     *
     * @return Attribute
     */
    public function payload(): Attribute
    {
        return Attribute::make(
            get: function ($payload) {
                if ($this->is_encryptable) {
                    try {
                        $payload = decrypt($payload);
                    } catch (DecryptException) {
                    }
                } else {
                    $payload = unserialize($payload);
                }

                if ($this->is_translatable) {
                    $fallbackLocale = fallbackLocale();
                    $payload = isset($payload[locale()])
                        ? $payload[locale()]
                        : ($payload[$fallbackLocale] ?? null);
                }

                return $payload;
            },
            set: function ($payload) {
                if ($this->is_encryptable) {
                    return encrypt($payload);
                } else {
                    return serialize($payload);
                }
            }
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_translatable' => 'boolean',
            'is_encryptable' => 'boolean',
        ];
    }
}
