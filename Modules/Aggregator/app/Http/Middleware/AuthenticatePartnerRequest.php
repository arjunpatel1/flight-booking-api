<?php

namespace Modules\Aggregator\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Aggregator\Models\PartnerApiCredential;
use Modules\Aggregator\Models\PartnerApiNonce;
use Modules\Aggregator\Services\PartnerApi\PartnerSignature;
use Modules\Aggregator\Support\PartnerApiResponse;
use Modules\Aggregator\Support\PartnerContext;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Models\Setting;
use Modules\Setting\Repositories\SettingRepository;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticatePartnerRequest
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PartnerSignature $signatures,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $maxBytes = (int) config('aggregator.partner.max_body_bytes', 1048576);
        if ((int) $request->server('CONTENT_LENGTH', 0) > $maxBytes || strlen($request->getContent()) > $maxBytes) {
            return PartnerApiResponse::error('PAYLOAD_TOO_LARGE', 'Request payload is too large.', 413);
        }

        $apiKey = trim((string) $request->header('X-Api-Key'));
        $timestamp = trim((string) $request->header('X-Timestamp'));
        $nonce = trim((string) $request->header('X-Nonce'));
        $signature = strtolower(trim((string) $request->header('X-Signature')));

        if ($apiKey === '' || ! ctype_digit($timestamp) || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce) || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return $this->unauthorized();
        }

        $credential = PartnerApiCredential::query()
            ->withoutGlobalScopes()
            ->with('partner')
            ->where('api_key', $apiKey)
            ->first();

        if (! $credential || ! $credential->partner || ! $this->credentialIsUsable($credential)) {
            return $this->unauthorized();
        }

        $signatureVersion = max(1, (int) ($credential->signature_version ?? 1));
        $requestedVersion = trim((string) $request->header('X-Signature-Version'));
        if ($requestedVersion !== '' && (! ctype_digit($requestedVersion) || (int) $requestedVersion !== $signatureVersion)) {
            return $this->unauthorized();
        }

        $skew = (int) config('aggregator.partner.clock_skew_seconds', 300);
        if (abs(now()->timestamp - (int) $timestamp) > $skew) {
            return PartnerApiResponse::error('STALE_REQUEST', 'Request timestamp is outside the allowed window.', 401);
        }

        if (! $this->ipAllowed($request->ip(), $credential->ip_allowlist ?? [])) {
            return $this->unauthorized();
        }

        if (! $this->signatures->verify(
            $signature, $credential->secret_ciphertext, $request->method(),
            $request->getRequestUri(), $timestamp, $nonce, $request->getContent(), $signatureVersion,
        )) {
            return $this->unauthorized();
        }

        if (! $this->claimNonce($credential, $nonce)) {
            return PartnerApiResponse::error('REPLAY_DETECTED', 'This signed request has already been used.', 409);
        }

        $rateKey = 'partner-api:rate:'.$credential->id.':'.now()->format('YmdHi');
        if (! RateLimiter::attempt($rateKey, max(1, (int) $credential->requests_per_minute), fn () => true, 60)) {
            return PartnerApiResponse::error('RATE_LIMITED', 'Request limit exceeded.', 429);
        }

        if ($this->isOrderWrite($request)) {
            $credentialKey = (string) $credential->id;
            if (! RateLimiter::attempt(
                "partner-api:orders:minute:{$credentialKey}",
                max(1, (int) $credential->orders_per_minute),
                fn () => true,
                60,
            )) {
                return PartnerApiResponse::error('ORDER_RATE_LIMITED', 'Order request limit exceeded.', 429);
            }
            if (! RateLimiter::attempt(
                "partner-api:orders:burst:{$credentialKey}",
                max(1, (int) $credential->burst_limit),
                fn () => true,
                10,
            )) {
                return PartnerApiResponse::error('ORDER_BURST_LIMITED', 'Order burst limit exceeded.', 429);
            }
        }

        $tenant = Tenant::query()->withoutGlobalScopes()->whereKey($credential->tenant_id)->where('is_active', true)->first();
        if (! $tenant) {
            return $this->unauthorized();
        }

        $context = new PartnerContext($credential, $credential->partner);
        $this->tenantContext->set($tenant);

        try {
            // Everything after installing tenant state belongs inside this
            // guard. A settings/cache/database failure must never leave a
            // previous partner tenant bound in a long-running worker.
            $this->rebindSettings();
            $request->attributes->set('tenant', $tenant);
            $request->attributes->set('tenant_id', $tenant->id);
            $request->attributes->set('partner_context', $context);

            try {
                // Usage telemetry is best effort; an otherwise valid signed
                // request must not fail because its timestamp could not save.
                $credential->forceFill(['last_used_at' => now()])->saveQuietly();
            } catch (Throwable $exception) {
                report($exception);
            }

            return $next($request);
        } finally {
            $this->tenantContext->clear();
            $this->rebindSettings();
        }
    }

    private function credentialIsUsable(PartnerApiCredential $credential): bool
    {
        if ((int) $credential->partner->tenant_id !== (int) $credential->tenant_id
            || $credential->partner->status !== 'active'
            || $credential->status === 'revoked') {
            return false;
        }

        if ($credential->status === 'rotating') {
            return (bool) $credential->grace_expires_at?->isFuture();
        }

        if ($credential->expires_at?->isPast()) {
            return false;
        }

        return $credential->status === 'active';
    }

    private function isOrderWrite(Request $request): bool
    {
        return ! in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)
            && preg_match('#(?:^|/)partner/(?:orders|deliveries)(?:/|$)#', $request->path()) === 1;
    }

    private function ipAllowed(?string $ip, array $allowlist): bool
    {
        return empty($allowlist) || ($ip !== null && IpUtils::checkIp($ip, $allowlist));
    }

    private function claimNonce(PartnerApiCredential $credential, string $nonce): bool
    {
        $hash = hash('sha256', $nonce);
        $ttl = max(60, (int) config('aggregator.partner.nonce_ttl_seconds', 600));

        try {
            return Cache::store('redis')->add("partner-api:nonce:{$credential->id}:{$hash}", true, $ttl);
        } catch (Throwable) {
            try {
                PartnerApiNonce::query()
                    ->where('credential_id', $credential->id)
                    ->where('expires_at', '<=', now())
                    ->delete();
                PartnerApiNonce::query()->create([
                    'credential_id' => $credential->id,
                    'nonce_hash' => $hash,
                    'expires_at' => now()->addSeconds($ttl),
                ]);

                return true;
            } catch (QueryException) {
                return false;
            }
        }
    }

    private function unauthorized(): Response
    {
        return PartnerApiResponse::error('AUTHENTICATION_FAILED', 'Partner authentication failed.', 401);
    }

    private function rebindSettings(): void
    {
        app()->forgetInstance('setting');
        app()->singleton('setting', fn () => new SettingRepository(Setting::allCached()));
    }
}
