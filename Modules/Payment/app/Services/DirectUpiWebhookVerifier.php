<?php

namespace Modules\Payment\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class DirectUpiWebhookVerifier
{
    public function verify(Request $request, string $secret): string
    {
        $timestamp = trim((string) $request->header('X-Webhook-Timestamp'));
        $signature = trim((string) $request->header('X-Webhook-Signature'));
        $raw = $request->getContent();
        abort_if($secret === '' || $timestamp === '' || $signature === '', Response::HTTP_UNAUTHORIZED);

        try {
            $time = Carbon::parse($timestamp);
        } catch (\Throwable) {
            abort(Response::HTTP_UNAUTHORIZED);
        }
        abort_if(abs(now()->diffInSeconds($time, false)) > 300, Response::HTTP_UNAUTHORIZED);
        abort_unless(hash_equals(hash_hmac('sha256', $timestamp.'.'.$raw, $secret), $signature), Response::HTTP_UNAUTHORIZED);

        return $raw;
    }
}
