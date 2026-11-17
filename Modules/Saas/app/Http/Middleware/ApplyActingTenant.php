<?php

namespace Modules\Saas\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Saas\Support\ActingTenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * For routes that skip EnsureAuthenticatedTenant because they are also open to
 * the public (flight catalog, meta, configuration, ...). Admin pages use them
 * too, so the platform Super Admin's chosen tenant must apply here as well.
 * Guests and every other user are unaffected (see ActingTenant).
 */
class ApplyActingTenant
{
    public function __construct(private readonly ActingTenant $actingTenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // No `auth` middleware here; the default (Sanctum) guard still resolves
        // the signed-in staff user from the bearer token, or null for guests.
        $user = $request->user();

        if ($error = $this->actingTenant->apply($request, $user)) {
            return $error;
        }

        return $next($request);
    }
}
