<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CompressResponse
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only compress JSON responses
        if ($response instanceof Response && str_contains($response->headers->get('Content-Type', ''), 'application/json')) {
            // Check if client accepts gzip encoding
            $acceptEncoding = strtolower($request->header('Accept-Encoding', ''));
            
            if (str_contains($acceptEncoding, 'gzip')) {
                $content = $response->getContent();
                
                // Only compress if content is larger than 1KB
                if (strlen($content) > 1024) {
                    $compressed = gzencode($content, 6);
                    
                    // Only use compressed version if it's actually smaller
                    if ($compressed !== false && strlen($compressed) < strlen($content)) {
                        $response->setContent($compressed);
                        $response->headers->set('Content-Encoding', 'gzip');
                        $response->headers->set('Content-Length', strlen($compressed));
                    }
                }
            }
        }

        return $response;
    }
}
