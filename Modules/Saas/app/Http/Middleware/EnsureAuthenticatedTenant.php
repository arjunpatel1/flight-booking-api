<?php

namespace Modules\Saas\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;
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
            && ! $user->hasRole(DefaultRole::EnterpriseAdmin->value)
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
