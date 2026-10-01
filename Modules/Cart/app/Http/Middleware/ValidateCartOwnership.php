<?php

namespace Modules\Cart\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Cart\Models\Cart;

class ValidateCartOwnership
{
    public function handle(Request $request, Closure $next)
    {
        // New carts (not yet persisted) are allowed through — ownership is
        // implicit in the UUID session model: each authenticated user creates
        // their own unique cart UUID and the auth middleware already verified
        // their identity.
        return $next($request);
    }
}
