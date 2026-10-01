<?php

namespace Modules\Saas\Services\CustomerApp;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Models\CustomerAppSession;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class CustomerAppSessionService
{
    public function __construct(
        private readonly CustomerAppManifestService $manifests,
        private readonly CustomerAppAuthorizationService $authorization,
    ) {
    }

    /** @return array{session: CustomerAppSession, token: string} */
    public function exchange(
        array $manifestEnvelope,
        string $appUuid,
        string $packageId,
        string $platform,
        string $installationId,
    ): array {
        $registration = $this->manifests->verify(
            $manifestEnvelope,
            expectedAppUuid: $appUuid,
            expectedPackageId: $packageId,
            expectedPlatform: $platform,
        );
        $registration = $this->authorization->validateRegistration($registration, $packageId, $platform);
        $plainToken = $this->plainToken();
        $expiresAt = CarbonImmutable::now('UTC')->addSeconds(
            max(300, (int) config('saas.customer_app.session_ttl_seconds', 86400))
        );

        $session = DB::transaction(function () use ($registration, $installationId, $plainToken, $expiresAt): CustomerAppSession {
            return CustomerAppSession::query()->withoutGlobalScopes()->updateOrCreate(
                [
                    'customer_app_registration_id' => $registration->getKey(),
                    'installation_id' => $installationId,
                ],
                [
                    'tenant_id' => $registration->tenant_id,
                    'token_hash' => hash('sha256', $plainToken),
                    'expires_at' => $expiresAt,
                    'last_seen_at' => null,
                    'revoked_at' => null,
                ],
            );
        });

        return ['session' => $session, 'token' => $plainToken];
    }

    public function resolve(string $plainToken, ?User $customer = null): CustomerAppSession
    {
        if ($plainToken === '' || strlen($plainToken) > 512) {
            throw new CustomerAppAuthorizationException('APP_SESSION_INVALID', 'Customer application session is invalid.', 401);
        }

        $session = CustomerAppSession::query()->withoutGlobalScopes()
            ->with(['registration.tenant', 'tenant'])
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if (! $session || $session->revoked_at || ! $session->expires_at?->isFuture()) {
            throw new CustomerAppAuthorizationException('APP_SESSION_EXPIRED', 'Customer application session has expired.', 401);
        }
        if (! $session->registration
            || (int) $session->tenant_id !== (int) $session->registration->tenant_id) {
            throw new CustomerAppAuthorizationException('APP_SESSION_INVALID', 'Customer application session is invalid.', 401);
        }
        if ($customer && (! $customer->hasRole(DefaultRole::Customer->value)
            || (int) $customer->tenant_id !== (int) $session->tenant_id)) {
            throw new CustomerAppAuthorizationException('CUSTOMER_FORBIDDEN', 'Customer access is not valid for this restaurant.', 403);
        }

        $this->authorization->validateRegistration(
            $session->registration,
            $session->registration->package_id,
            $session->registration->platform,
            customer: $customer,
        );

        if (! $session->last_seen_at || $session->last_seen_at->lt(now()->subMinutes(5))) {
            $session->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $session;
    }

    private function plainToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }
}
