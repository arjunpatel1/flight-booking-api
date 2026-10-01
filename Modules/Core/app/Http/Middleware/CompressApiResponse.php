<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class CompressApiResponse
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return SymfonyResponse
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $response = $next($request);

        // Only compress JSON API responses
        if ($this->shouldCompress($request, $response)) {
            $response->setContent(gzencode($response->getContent(), 9));
            $response->headers->set('Content-Encoding', 'gzip');
            $response->headers->set('Content-Type', 'application/json');
            $response->headers->set('X-Content-Encoded-By', 'Laravel');
        }

        return $response;
    }

    /**
     * Determine if the response should be compressed.
     *
     * @param Request $request
     * @param SymfonyResponse $response
     * @return bool
     */
    protected function shouldCompress(Request $request, SymfonyResponse $response): bool
    {
        // Don't compress if response is already encoded
        if ($response->headers->has('Content-Encoding')) {
            return false;
        }

        // Only compress JSON responses
        if (!str_contains($response->headers->get('Content-Type', ''), 'application/json')) {
            return false;
        }

        // Check if client accepts gzip encoding
        $acceptEncoding = $request->header('Accept-Encoding', '');
        if (!str_contains(strtolower($acceptEncoding), 'gzip')) {
            return false;
        }

        // Only compress responses larger than 1KB
        if (strlen($response->getContent()) < 1024) {
            return false;
        }

        return true;
    }
}
