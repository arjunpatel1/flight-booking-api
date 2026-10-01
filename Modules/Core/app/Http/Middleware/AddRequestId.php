<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

class AddRequestId
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Generate or retrieve request ID
        $candidate = trim((string) $request->header('X-Request-ID'));
        $requestId = $candidate !== '' && Uuid::isValid($candidate)
            ? $candidate
            : (string) Uuid::uuid4();
        
        // Add request ID to request for use in controllers
        $request->attributes->set('request_id', $requestId);
        
        $response = $next($request);
        
        // Add request ID to response headers
        $response->headers->set('X-Request-ID', $requestId);
        
        return $response;
    }
}
