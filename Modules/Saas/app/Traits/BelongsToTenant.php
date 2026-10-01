<?php

namespace Modules\Saas\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Tenant isolation for tables that carry a `tenant_id` but no `branch_id`.
 *
 * `HasBranch` cannot help these: it reaches a tenant through
 * `branches.tenant_id`, so a table with no branch column gets no filtering at
 * all. That left subscription and alert rows readable across tenants by any
 * authenticated tenant user.
 *
 * Exemptions, matching HasBranch so behaviour stays predictable:
 *  - no authenticated user (queue jobs, console commands, public endpoints)
 *  - platform super admins, who legitimately administer every tenant
 *  - users with no tenant of their own
 *
 * Use `withoutGlobalTenant()` for deliberate control-plane queries.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant_permission', function (Builder $builder) {
            if (! auth()->guard()->hasUser()) {
                return;
            }

            $user = auth()->user();

            if (! $user || $user->isSuperAdmin() || ! $user->assignedToTenant()) {
                return;
            }

            $builder->where(
                $builder->getModel()->getTable() . '.tenant_id',
                $user->tenantId()
            );
        });

        // Stamp ownership on create so a tenant user cannot author a row that
        // then belongs to nobody — and becomes invisible to the scope above.
        static::creating(function ($model) {
            $user = auth()->user();

            if (blank($model->tenant_id) && $user?->assignedToTenant() && ! $user->isSuperAdmin()) {
                $model->tenant_id = $user->tenantId();
            }
        });
    }

    public function scopeWithoutGlobalTenant(Builder $query): void
    {
        $query->withoutGlobalScope('tenant_permission');
    }
}
