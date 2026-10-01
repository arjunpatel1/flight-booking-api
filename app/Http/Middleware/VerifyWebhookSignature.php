<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Webhook-Signature');
        if (! $signature) {
            return response()->json([
                'message' => 'Webhook signature is required.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $webhookSecret = config('services.whatsapp.webhook_secret');
        if (! $webhookSecret) {
            return response()->json([
                'message' => 'Webhook secret not configured.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $expectedSignature = hash_hmac('sha256', $request->getContent(), $webhookSecret);
        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'message' => 'Invalid webhook signature.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
