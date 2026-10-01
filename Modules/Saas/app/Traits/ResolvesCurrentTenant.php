<?php

namespace Modules\Saas\Traits;

use Illuminate\Http\Request;
use Modules\Saas\Models\Tenant;

/**
 * Resolves "the restaurant the caller belongs to" for tenant self-service
 * endpoints — the ones a restaurant owner uses about their own restaurant,
 * as opposed to the control-plane endpoints that take an explicit tenant id.
 *
 * Global scopes are bypassed deliberately: the tenant row is the boundary
 * itself, and it is selected by the authenticated user's own tenant_id, so
 * there is nothing to filter against.
 */
trait ResolvesCurrentTenant
{
    protected function currentTenant(Request $request): Tenant
    {
        $tenantId = $request->user()?->tenant_id;
        abort_unless($tenantId, 422, 'The current account is not assigned to a restaurant.');

        return Tenant::query()->withoutGlobalScopes()->withoutGlobalActive()->findOrFail($tenantId);
    }
}
