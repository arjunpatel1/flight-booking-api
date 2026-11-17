<?php

namespace Modules\Saas\Support;

use Illuminate\Http\Request;
use Modules\Flight\Models\FlightCustomer;
use Modules\Saas\Models\Tenant;
use Modules\Setting\Models\Setting;
use Modules\Setting\Repositories\SettingRepository;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform Super Admin working inside one tenant, chosen in the Tenant
 * filter on the admin pages (sent as X-Tenant-ID).
 *
 * Only the platform Super Admin can do this: isSuperAdmin() requires no tenant
 * and no branch, so a tenant user sending the header keeps their own tenant.
 * The tenant is applied exactly as a tenant domain would apply it (context,
 * request attributes, tenant settings), so tenant scopes and isolation work the
 * same way for every endpoint. Platform (control-plane) endpoints always work
 * across all tenants.
 *
 * Used by EnsureAuthenticatedTenant (signed-in API) and ApplyActingTenant
 * (the flight routes that are also open to the public, such as the catalog).
 */
class ActingTenant
{
    private const CONTROL_PLANE_PATHS = [
        'api/v1/saas/*',
        'api/v1/tenants', 'api/v1/tenants/*',
        'api/v1/tenant-subscriptions', 'api/v1/tenant-subscriptions/*',
        'api/v1/subscription-plans', 'api/v1/subscription-plans/*',
    ];

    public function __construct(private readonly TenantContext $context)
    {
    }

    /**
     * Apply the chosen tenant when the request is allowed to carry one.
     * Returns an error response for a tenant that no longer exists, else null.
     */
    public function apply(Request $request, mixed $user): ?Response
    {
        // "tenants/current/*" means the tenant being worked in, so it follows the
        // chosen tenant; the rest of /tenants is the platform registry.
        $controlPlane = $request->is(self::CONTROL_PLANE_PATHS) && ! $request->is('api/v1/tenants/current', 'api/v1/tenants/current/*');
        if ($this->context->get() !== null || $controlPlane) {
            return null;
        }

        $actingTenantId = $request->header('X-Tenant-ID');
        if (! filled($actingTenantId) || $user === null || $user instanceof FlightCustomer
            || ! method_exists($user, 'isSuperAdmin') || ! $user->isSuperAdmin()) {
            return null;
        }

        $acting = ctype_digit((string) $actingTenantId)
            ? Tenant::query()->withoutGlobalScopes()->find((int) $actingTenantId)
            : null;
        if (! $acting) {
            return ApiResponse::errors(
                errors: ['code' => 'ACTING_TENANT_NOT_FOUND'],
                message: 'The selected tenant no longer exists. Choose another tenant.',
                code: Response::HTTP_NOT_FOUND,
            );
        }

        $this->context->set($acting);
        $request->attributes->set('tenant', $acting);
        $request->attributes->set('tenant_id', $acting->id);
        // Read by the BelongsToTenant scope: tenant-scoped queries are limited to it.
        $request->attributes->set('acting_tenant_id', $acting->id);
        // Settings are tenant-scoped and were loaded for "no tenant".
        app()->forgetInstance('setting');
        app()->singleton('setting', fn () => new SettingRepository(Setting::allCached()));

        return null;
    }
}
