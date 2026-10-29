<?php

namespace Modules\Flight\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Modules\Flight\Services\Catalog\FlightCatalogTenant;

/**
 * Tenant isolation for the flight reference catalog (airports, airlines,
 * aircraft, cabin classes).
 *
 * Unlike BelongsToTenant, rows with a NULL tenant_id are meaningful: they are
 * the platform starter template copied into new tenants. A tenant user only
 * ever sees its own copy; platform admins, console commands and cross-tenant
 * public search are unrestricted so relations to any tenant's rows resolve.
 */
trait BelongsToTenantCatalog
{
    public static function bootBelongsToTenantCatalog(): void
    {
        static::addGlobalScope('tenant_catalog', function (Builder $builder) {
            $tenantId = FlightCatalogTenant::id();

            if ($tenantId !== null) {
                $builder->where($builder->getModel()->getTable() . '.tenant_id', $tenantId);
            }
        });

        static::creating(function ($model) {
            if (blank($model->tenant_id)) {
                $model->tenant_id = FlightCatalogTenant::id();
            }
        });
    }

    /**
     * The catalog a directory screen manages: the tenant's own rows, or the
     * starter template for platform admins.
     */
    public function scopeOwnedByCatalog(Builder $query, ?int $tenantId = null): void
    {
        $tenantId ??= FlightCatalogTenant::ownerId();

        $query->withoutGlobalScope('tenant_catalog');

        $tenantId === null
            ? $query->whereNull($this->getTable() . '.tenant_id')
            : $query->where($this->getTable() . '.tenant_id', $tenantId);
    }
}
