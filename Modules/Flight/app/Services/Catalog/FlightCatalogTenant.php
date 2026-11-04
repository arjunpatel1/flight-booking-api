<?php

namespace Modules\Flight\Services\Catalog;

use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Modules\Saas\Support\TenantContext;
use Modules\Saas\Support\TenantDomainResolver;
use Modules\User\Models\User;
use Throwable;

/**
 * Which tenant's reference catalog (airports, airlines, aircraft, cabin
 * classes) the current request works with.
 *
 * Each tenant owns its own copy of the catalog. Rows with a NULL tenant_id are
 * the platform starter template, copied into every new tenant and managed by
 * platform admins.
 *
 *  - id():      the tenant to restrict reads to, or null for "unrestricted"
 *               (platform admins, console, cross-tenant public search). Used
 *               by the model global scope.
 *  - ownerId(): the catalog a directory screen manages. Same as id(), except
 *               that "unrestricted" means the starter template for signed-in
 *               platform admins, and for guests the agency whose saved domain the
 *               site is on (TenantDomainResolver::publicSiteTenantId).
 */
class FlightCatalogTenant
{
    public static function id(): ?int
    {
        try {
            $user = auth()->user();
        } catch (Throwable) {
            $user = null;
        }

        // Flight customers (a separate model) book across tenants; only staff
        // accounts are pinned to one tenant's catalog.
        if ($user instanceof User && $user->tenantId() !== null) {
            return $user->tenantId();
        }

        return app(TenantContext::class)->id();
    }

    public static function ownerId(): ?int
    {
        $id = self::id();
        if ($id !== null) {
            return $id;
        }

        // A public visitor on the platform domain has no tenant from the
        // hostname, yet the public site belongs to the agency whose saved
        // domain it is, so show that agency's airports and airlines rather
        // than the starter template. Signed-in platform admins keep the template.
        return self::isPublicVisitor() && app()->bound('request')
            ? app(TenantDomainResolver::class)->publicSiteTenantId(request())
            : null;
    }

    /** Guests and flight customers (a separate model), as opposed to staff accounts. */
    public static function isPublicVisitor(): bool
    {
        try {
            return ! auth()->user() instanceof User;
        } catch (Throwable) {
            return true;
        }
    }

    /** `exists` rule restricted to the given (default: current) tenant's catalog. */
    public static function exists(string $table, string $column = 'id', ?int $tenantId = null): Exists
    {
        $tenantId ??= self::id();
        $rule = \Illuminate\Validation\Rule::exists($table, $column);

        return $tenantId === null ? $rule : $rule->where('tenant_id', $tenantId);
    }

    /** `exists` rule for an active cabin class code in the current tenant's catalog. */
    public static function activeCabinClass(?int $tenantId = null): Exists
    {
        return self::exists('flight_cabin_classes', 'code', $tenantId)->where('is_active', true);
    }

    /** `unique` rule within the catalog the directory screen manages. */
    public static function unique(string $table, string $column): Unique
    {
        $ownerId = self::ownerId();
        $rule = \Illuminate\Validation\Rule::unique($table, $column);

        return $ownerId === null
            ? $rule->whereNull('tenant_id')
            : $rule->where('tenant_id', $ownerId);
    }
}
