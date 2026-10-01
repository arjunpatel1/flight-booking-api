<?php

namespace Modules\Order\Delivery;

/** Authenticate the custom static Bearer header configured in the uEngage portal. */
final class UengageWebhookAuthenticator
{
    public function authenticate(string $rawBody, array $headers): bool
    {
        $configuredHash = strtolower(trim((string) config('delivery.webhook_token_hash')));
        if (! preg_match('/^[a-f0-9]{64}$/', $configuredHash)) return false;

        $authorization = $headers['authorization'][0] ?? $headers['Authorization'][0] ?? null;
        // uEngage accepts 24-character custom webhook tokens. Keep the upper
        // bound and hash comparison so the token is never stored or logged.
        if (! is_string($authorization) || ! preg_match('/^Bearer\s+([^\s]{24,512})$/i', trim($authorization), $matches)) {
            return false;
        }

        return hash_equals($configuredHash, hash('sha256', $matches[1]));
    }
}
