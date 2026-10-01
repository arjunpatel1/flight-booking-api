<?php

namespace Modules\Order\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Carbon;
use Throwable;

final class CustomerTrackingToken
{
    public function issue(int $tenantId, string $reference, int $days = 30): string
    {
        return Crypt::encryptString(json_encode([
            'tenant_id' => $tenantId,
            'reference' => $reference,
            'expires_at' => now()->addDays($days)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public function validate(string $token, string $reference): ?int
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload)
            || ! is_int($payload['tenant_id'] ?? null)
            || ! is_string($payload['reference'] ?? null)
            || ! hash_equals($payload['reference'], $reference)
            || ! is_int($payload['expires_at'] ?? null)
            || Carbon::createFromTimestamp($payload['expires_at'])->isPast()) {
            return null;
        }

        return $payload['tenant_id'];
    }
}
