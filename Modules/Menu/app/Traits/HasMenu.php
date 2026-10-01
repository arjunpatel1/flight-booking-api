<?php

namespace Modules\Menu\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Menu\Models\Menu;

/**
 * @property int $menu_id
 * @property-read  Menu $menu
 *
 * @method static Builder|static whereMenu(int $id)
 * @method static Builder|static withOutGlobalBranchPermission()
 */
trait HasMenu
{
    /**
     * The menu key.
     *
     * @var string
     */
    const MENU_COLUMN_NAME = 'menu_id';

    /**
     * Boot HasActiveStatus trait
     *
     * @return void
     */
    public static function bootHasMenu(): void
    {
        static::addGlobalScope("branch_permission", function (Builder $builder) {
            if (! auth()->guard()->hasUser()) {
                return;
            }

            $user = auth()->user();
            if (! $user?->assignedToBranch()) {
                return;
            }

            $builder->whereHas(
                'menu',
                fn(Builder $query) => $query->where('menus.branch_id', $user->branch_id)
            );
        });

        /*
         * Tenant-level isolation.
         *
         * Menu-owned records (products, options and anything else using this
         * trait) reach a branch through their menu, so the branch scope above
         * only fires for a user that has a branch_id. A tenant owner
         * deliberately has none — it governs every branch in its tenant — which
         * left it with NO filter at all and a view of every menu-owned record
         * on the platform, across all tenants.
         *
         * This mirrors HasBranch::tenant_branch_permission: restrict to menus
         * belonging to branches of the caller's own tenant.
         */
        static::addGlobalScope("tenant_branch_permission", function (Builder $builder) {
            if (! auth()->guard()->hasUser()) {
                return;
            }

            $user = auth()->user();
            if (! $user || $user->assignedToBranch() || ! $user->assignedToTenant() || $user->isSuperAdmin()) {
                return;
            }

            $builder->whereHas('menu', fn(Builder $query) => $query->whereIn(
                'menus.branch_id',
                fn($sub) => $sub->select('id')->from('branches')->where('tenant_id', $user->tenantId())
            ));
        });
    }

    /**
     * Get a model menu
     *
     * @return BelongsTo
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, self::MENU_COLUMN_NAME)
            ->withoutGlobalActive()
            ->withTrashed();
    }

    /**
     * Scope a query without global branch permission scope.
     *
     * @param Builder $query
     * @return void
     */
    public function scopeWithOutGlobalBranchPermission(Builder $query): void
    {
        $query
            ->withOutGlobalScope("branch_permission")
            ->withOutGlobalScope("tenant_branch_permission");
    }

    /**
     * Scope a query to only include records a specific menu.
     *
     * @param Builder $query
     * @param int $id
     * @return void
     */
    public function scopeWhereMenu(Builder $query, int $id): void
    {
        $query->where(static::MENU_COLUMN_NAME, $id);
    }
}
