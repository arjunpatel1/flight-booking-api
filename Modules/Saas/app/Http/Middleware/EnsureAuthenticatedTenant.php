<?php

namespace Modules\Saas\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;
use Modules\Flight\Models\FlightCustomer;
use Modules\Saas\Support\TenantContext;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticatedTenant
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->get();
        $user = $request->user();

        // Flight customers authenticate through their own Sanctum model and
        // do not have staff tenant/role helpers such as isSuperAdmin(). Their
        // customer-auth endpoints enforce ownership using the customer token.
        if ($user instanceof FlightCustomer && $request->is('api/v1/flight/customer-auth/*')) {
            return $next($request);
        }

        // When running on localhost/loopback or servicing flight-domain APIs,
        // resolve tenant context from the authenticated user if the browser host
        // does not specify a distinct tenant subdomain.
        if ($tenant === null && $user !== null && $user->assignedToTenant()) {
            $origin = $request->headers->get('Origin') ?? $request->headers->get('Referer');
            $host = $origin ? parse_url($origin, PHP_URL_HOST) : $request->getHost();
            if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || $request->is('api/v1/flight/*') || app()->environment('local', 'testing')) {
                $tenant = $user->tenant;
                if ($tenant) {
                    $this->context->set($tenant);
                    $request->attributes->set('tenant', $tenant);
                    $request->attributes->set('tenant_id', $tenant->id);
                }
            }
        }

        // Browser requests always identify the page hostname. A restaurant
        // identity must never operate from a central/platform hostname, even
        // though its token still contains a tenant_id. This prevents a support
        // handoff or stale browser session from replacing the NexDine console
        // with a restaurant workspace on the platform domain.
        $hasBrowserDomain = filled($request->header('X-NexDine-Tenant-Domain'))
            || filled($request->header('Origin'))
            || filled($request->header('Referer'));
        if ($tenant === null && $user !== null && $user->assignedToTenant() && $hasBrowserDomain) {
            return $this->denied($request, 'TENANT_DOMAIN_REQUIRED');
        }

        if ($tenant !== null && $user !== null && !$user->isSuperAdmin()) {
            if ((int) $user->tenant_id !== (int) $tenant->id) {
                return $this->denied($request, 'TENANT_MISMATCH');
            }
        }

        if (
            $user !== null
            && ! $user->isSuperAdmin()
            && $user->assignedToTenant()
            && ! $request->is('api/v1/flight/*')
            && ! $user->hasRole([
                DefaultRole::EnterpriseAdmin->value,
                DefaultRole::FlightAdmin->value,
                DefaultRole::FlightDistributor->value,
                DefaultRole::FlightAgent->value,
                DefaultRole::FlightCustomer->value,
            ])
        ) {
            if (! $user->assignedToBranch()) {
                return $this->denied($request, 'BRANCH_FORBIDDEN');
            }
        }

        $branchId = $this->requestedBranchId($request);
        if ($branchId !== null && $user !== null && !$user->isSuperAdmin()) {
            if ($user->assignedToBranch()) {
                if ((int) $user->branch_id !== $branchId) {
                    return $this->denied($request, 'BRANCH_FORBIDDEN');
                }
            }

            if ($user->assignedToTenant()) {
                $branchBelongsToTenant = Branch::query()
                        ->withoutGlobalScopes()
                        ->whereKey($branchId)
                        ->where('tenant_id', $user->tenant_id)
                        ->exists();

                if (! $branchBelongsToTenant) {
                    return $this->denied($request, 'BRANCH_FORBIDDEN');
                }
            }
        }

        return $next($request);
    }

    private function denied(Request $request, string $machineCode): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return ApiResponse::errors(
                errors: ['code' => $machineCode],
                message: $machineCode === 'TENANT_DOMAIN_REQUIRED'
                    ? 'This restaurant session must be opened from its registered domain.'
                    : __('auth.failed'),
                code: Response::HTTP_FORBIDDEN,
            );
        }

        abort(Response::HTTP_FORBIDDEN, __('auth.failed'));
    }

    private function requestedBranchId(Request $request): ?int
    {
        $branchId = $request->input('branch_id')
            ?? $request->query('branch_id')
            ?? data_get($request->input('filters'), 'branch_id')
            ?? data_get($request->query('filters'), 'branch_id');

        return is_numeric($branchId) ? (int) $branchId : null;
    }
}
