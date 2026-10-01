<?php

namespace Modules\Installer\Http\Middleware;

use App\NexDine;
use Closure;
use Illuminate\Http\Request;

class CheckInstalled
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        if (!NexDine::installed()) {
            return redirect()->route('installer.welcome');
        }

        return $next($request);
    }
}
