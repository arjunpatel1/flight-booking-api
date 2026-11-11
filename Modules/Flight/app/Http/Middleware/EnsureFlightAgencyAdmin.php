<?php

namespace Modules\Flight\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;
use Symfony\Component\HttpFoundation\Response;

/**
 * Money and partner administration (B2B wallets, commission rules) is for the
 * agency's administrators only. Without this, any signed-in user of the
 * agency — including a B2B agent — could recharge their own wallet or raise
 * their own commission.
 */
class EnsureFlightAgencyAdmin
{
    public const ADMIN_ROLES = [
        DefaultRole::SuperAdmin,
        DefaultRole::Admin,
        DefaultRole::EnterpriseAdmin,
        DefaultRole::AdminBranch,
        DefaultRole::FlightAdmin,
    ];

    public static function allows($user): bool
    {
        if (! $user) {
            return false;
        }

        return (bool) ($user->is_super_admin ?? false)
            || (bool) ($user->is_admin ?? false)
            || (method_exists($user, 'hasAnyRole')
                && $user->hasAnyRole(array_map(fn (DefaultRole $role) => $role->value, self::ADMIN_ROLES)));
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::allows($request->user())) {
            return ApiResponse::errors(null, 'Only agency administrators can do this.', 403);
        }

        return $next($request);
    }
}
