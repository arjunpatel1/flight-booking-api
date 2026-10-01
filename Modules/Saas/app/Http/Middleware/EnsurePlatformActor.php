<?php

namespace Modules\Saas\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense-in-depth boundary for the SaaS control plane.
 *
 * A permission is necessary, but it is not sufficient: tenant and branch
 * identities are never valid platform operators, even if a role is
 * accidentally or maliciously granted an admin.saas permission.
 */
class EnsurePlatformActor
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();

        if (! $actor || $actor->assignedToTenant() || $actor->assignedToBranch()) {
            return ApiResponse::errors(
                errors: ['code' => 'CONTROL_PLANE_FORBIDDEN'],
                // Authorization denial must remain available even when the
                // database-backed translation loader is degraded.
                message: 'Forbidden.',
                code: Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
