<?php

namespace Modules\Saas\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCustomerAppBuildWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('saas.customer_app_build.worker_token');
        $provided = (string) $request->bearerToken();
        $workerId = (string) $request->header('X-Build-Worker-ID');

        if (strlen($configured) < 32 || strlen($provided) < 32
            || ! hash_equals(hash('sha256', $configured), hash('sha256', $provided))) {
            return response()->json(['message' => 'Build worker authentication failed.', 'code' => 'BUILD_WORKER_UNAUTHENTICATED'], 401);
        }
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{2,95}\z/', $workerId)) {
            return response()->json(['message' => 'A valid build worker identity is required.', 'code' => 'BUILD_WORKER_ID_INVALID'], 422);
        }

        $request->attributes->set('customer_app_build_worker_id', $workerId);

        return $next($request);
    }
}
