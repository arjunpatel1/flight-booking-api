<?php

namespace Modules\Installer\Http\Middleware;

use App\NexDine;
use Closure;
use Illuminate\Http\Request;

class CheckInstallation
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        if (NexDine::installed()) {
            return redirect()->to('/');
        }

        return $next($request);
    }
}
