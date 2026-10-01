<?php

namespace Modules\Notification\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class MailgunWebhookVerifier
{
    public function verify(Request $request, string $scope): void
    {
        $timestamp = $this->scalarInput($request, 'signature.timestamp', 'timestamp');
        $token = $this->scalarInput($request, 'signature.token', 'token');
        $signature = $this->scalarInput($request, 'signature.signature', 'signature');
        $signingKey = (string) config('services.mailgun.webhook_signing_key');
        abort_if($signingKey === '' || ! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > 300, Response::HTTP_UNAUTHORIZED);
        abort_unless(hash_equals(hash_hmac('sha256', $timestamp.$token, $signingKey), $signature), Response::HTTP_UNAUTHORIZED);
        abort_unless(Cache::add("mailgun-{$scope}:".hash('sha256', $timestamp.$token), true, now()->addMinutes(10)), Response::HTTP_CONFLICT);
    }

    private function scalarInput(Request $request, string $nested, string $flat): string
    {
        $value = $request->input($nested);
        if (! is_scalar($value)) $value = $request->input($flat);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
