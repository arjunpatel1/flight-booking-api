<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Translation\Traits\Translatable;
use Modules\User\Enums\DefaultRole;
use Spatie\Permission\Models\Role as BaseRole;

/**
 * @property string $name
 * @property string $display_name
 * @property bool|int $built_in
 *
 * @method static Builder|self builtIn(bool $operator = true)
 * @method static Builder|self notBuiltIn()
 *
 * @mixin Builder
 */
class Role extends BaseRole
{
    use HasActivityLog,
        HasCreatedBy,
        HasTagsCache,
        HasSortBy,
        HasFilters,
        Translatable;

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['display_name'];

    /**
     * Perform any actions required after the model boots.
     *
     * @return void
     */
    public static function booted(): void
    {
        static::creating(function (Role $role) {
            if (empty($role->name)) {
                $role->name = static::getSlugName($role->display_name);
            }
        });
    }

    /**
     * Get slug name
     *
     * @param string|array|null $displayName
     * @return string
     */
    public static function getSlugName(string|array|null $displayName): string
    {
        return uniqid(Str::slug(is_array($displayName)
                ? (isset($displayName[setting('default_locale')])
                    ? $displayName[setting('default_locale')]
                    : $displayName[array_key_first($displayName)])
                : $displayName) . "_");
    }

    /**
     * Get a list of all roles.
     *
     * @param bool $forBranch
     * @param bool $withCustomer
     * @return Collection
     */
    public static function list(bool $forBranch = false, bool $withCustomer = false): Collection
    {
        $actor = auth()->user();
        $roleScope = $actor?->isSuperAdmin() && ! $actor->assignedToTenant() && ! $actor->assignedToBranch()
            ? 'platform'
            : 'tenant:'.($actor?->tenantId() ?? 'none');

        return Cache::tags("roles")
            ->rememberForever(
                makeCacheKey([
                    "roles",
                    $forBranch ? "for_branch" : "",
                    $withCustomer ? "all" : "without_customer",
                    $roleScope,
                    "list"
                ]),
                function () use ($forBranch, $withCustomer, $actor) {
                    $roles = static::select('id', 'display_name', 'name', 'built_in')
                        ->visibleTo($actor)
                        ->when(!$withCustomer, fn($query) => $query->whereNot('name', DefaultRole::Customer->value))
                        ->get();
                    if ($forBranch) {
                        // Branch staff may use custom roles created by their
                        // administrator. Only platform-only built-in roles are
                        // excluded; filtering solely by the enum hid every new
                        // custom role from the employee form.
                        $roles = $roles->filter(fn(Role $role) => ! $role->built_in
                            || in_array($role->name, DefaultRole::getBranchAvailableRoles(), true));
                    }
                    return $roles->map(fn(Role $role) => [
                        'id' => $role->id,
                        'key' => $role->name,
                        'name' => $role->display_name
                    ])->values();
                });
    }

    /**
     * Built-in roles are platform definitions. A custom role is visible only
     * inside the tenant of the user who created it.
     */
    public function scopeVisibleTo(Builder $query, ?User $actor): Builder
    {
        if ($actor?->isSuperAdmin() && ! $actor->assignedToTenant() && ! $actor->assignedToBranch()) {
            return $query;
        }

        if (! $actor?->tenantId()) {
            return $query->where('built_in', true)
                ->when($actor?->assignedToBranch(), fn (Builder $roles) => $roles
                    ->whereIn('name', DefaultRole::getBranchAvailableRoles()));
        }

        return $query->where(function (Builder $visible) use ($actor) {
            $visible->where(fn (Builder $builtIn) => $builtIn
                ->where('built_in', true)
                ->whereIn('name', DefaultRole::getBranchAvailableRoles()))
                ->orWhereHas('createdBy', fn (Builder $creator) => $creator
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $actor->tenantId()));
        });
    }

    /**
     * Scope a query to where Built-In.
     *
     * @param Builder $query
     * @param bool $operator
     * @return void
     * @noinspection PhpUnused
     */
    public function scopeBuiltIn(Builder $query, bool $operator = true): void
    {
        $query->where('built_in', $operator);
    }

    /**
     * Scope a query to where not Built-In.
     *
     * @param Builder $query
     * @return void
     * @noinspection PhpUnused
     */
    public function scopeNotBuiltIn(Builder $query): void
    {
        /** @var self $query */
        $query->builtIn(false);
    }

    /**
     * Determine if a role is build-in
     *
     * @return bool
     */
    public function isBuiltIn(): bool
    {
        return (bool)$this->built_in;
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            "built_in",
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
            $query->whereLikeTranslation('display_name', $value);
        });
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
        ];
    }
}
