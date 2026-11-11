<?php

namespace Modules\Flight\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Flight\Models\FlightAgent;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;
use Symfony\Component\HttpFoundation\Response;

/**
 * A B2B partner's login belongs to the agency's tenant, so tenant scoping alone
 * treats it as agency staff. This keeps partners to the B2B workspace: they may
 * use the public endpoints (search, holds, quotes, their own booking/e-ticket,
 * which EnsureFlightBookingAccess scopes to their bookings) and their own B2B
 * endpoints. Every other staff endpoint is refused, including ones added later.
 */
class RestrictB2BPartnerAccess
{
    /** Signed-in flight endpoints a partner may call (relative to api/v1/flight/). */
    private const PARTNER_ROUTES = [
        'b2b/workspace',
        'b2b/bookings',
        'b2b/bookings/{identifier}/pay-wallet',
        'b2b/bookings/{identifier}/cancel',
        'b2b/inventory',
        // Their own record only: the controller refuses any other agent's id.
        'b2b/agents/{id}/profile',
        'b2b/agents/{id}/wallet',
        'b2b/agents/{id}/inventory',
    ];

    /** Roles that make a user agency staff even if they also hold Flight Agent. */
    private const STAFF_ROLES = [
        DefaultRole::Manager,
        DefaultRole::FlightDistributor,
    ];

    /** A B2B partner: a Flight Agent login (or one linked to an agent) that is not agency staff. */
    public static function isPartner($user): bool
    {
        if (! $user || EnsureFlightAgencyAdmin::allows($user)) {
            return false;
        }

        if (method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(array_map(fn (DefaultRole $role) => $role->value, self::STAFF_ROLES))) {
            return false;
        }

        return (method_exists($user, 'hasRole') && $user->hasRole(DefaultRole::FlightAgent->value))
            || FlightAgent::query()->where('user_id', $user->id)->exists();
    }

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        // Public endpoints guard themselves (EnsureFlightBookingAccess etc.).
        if (! $route || in_array('auth', (array) $route->excludedMiddleware(), true)) {
            return $next($request);
        }

        $path = preg_replace('#^api/v\d+/flight/#', '', $route->uri());
        if (in_array($path, self::PARTNER_ROUTES, true) || ! self::isPartner($request->user())) {
            return $next($request);
        }

        return ApiResponse::errors(null, 'B2B partners can only use the B2B Booking Workspace.', 403);
    }
}
