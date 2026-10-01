<?php

namespace Modules\Saas\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Modules\Saas\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantPlanFeature
{
    public function __construct(
        private readonly EffectiveTenantEntitlementService $entitlements,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$features): mixed
    {
        $user = $request->user();

        // Platform users operate the control plane and are not governed by a
        // restaurant subscription. Authentication remains the responsibility
        // of the API route group.
        if ($user && ! $user->assignedToTenant()) {
            return $next($request);
        }

        $features = array_values(array_unique(array_filter(
            array_map(static fn (string $feature) => trim($feature), $features)
        )));

        if ($features === []) {
            return $next($request);
        }

        $tenantId = $user?->tenantId() ?? $this->tenantContext->id();
        if ($tenantId === null) {
            // Central public callbacks identify their tenant inside a signed
            // controller request and therefore have no domain tenant context.
            return $next($request);
        }

        $tenant = Tenant::query()->withoutGlobalScopes()->whereKey($tenantId)->where('is_active', true)->first();
        $enabled = $tenant && collect($features)->contains(
            fn (string $feature) => $this->entitlements->has($tenant, $feature)
        );

        if ($enabled) {
            return $next($request);
        }

        return new JsonResponse([
            'message' => 'This feature is not included in the restaurant subscription.',
            'errors' => [
                'code' => 'PLAN_FEATURE_DISABLED',
                'features' => $features,
            ],
        ], Response::HTTP_FORBIDDEN);
    }
}
