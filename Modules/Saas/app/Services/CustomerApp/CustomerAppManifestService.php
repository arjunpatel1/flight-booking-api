<?php

namespace Modules\Saas\Services\CustomerApp;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Models\CustomerAppRegistration;
use RuntimeException;

class CustomerAppManifestService
{
    public const SCHEMA_VERSION = 1;
    public const ALGORITHM = 'RS256';

    public function __construct(private readonly CustomerAppEntitlementService $entitlements)
    {
    }

    public function issue(CustomerAppRegistration $registration, ?CarbonImmutable $now = null): array
    {
        $registration->loadMissing('tenant');
        $this->assertRegistrationIsUsable($registration);
        $now ??= CarbonImmutable::now('UTC');
        $ttl = max(30, (int) config('saas.customer_app.manifest_ttl_seconds', 300));
        $manifest = [
            'schema_version' => self::SCHEMA_VERSION,
            'manifest_id' => (string) Str::uuid(),
            'app_uuid' => $registration->uuid,
            'package_id' => $registration->package_id,
            'platform' => $registration->platform,
            'api_origin' => rtrim((string) config('saas.customer_app.api_origin'), '/'),
            'branding_revision' => (int) $registration->branding_revision,
            'issued_at' => $now->toIso8601String(),
            'expires_at' => $now->addSeconds($ttl)->toIso8601String(),
        ];

        $privateKey = openssl_pkey_get_private($this->key('manifest_private_key'));
        if (! $privateKey) {
            throw new RuntimeException('Customer App manifest private key is not configured.');
        }
        if (! openssl_sign($this->canonical($manifest), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Customer App manifest could not be signed.');
        }

        return ['algorithm' => self::ALGORITHM, 'manifest' => $manifest, 'signature' => $this->base64UrlEncode($signature)];
    }

    public function verify(
        array $envelope,
        ?CarbonImmutable $now = null,
        ?string $expectedAppUuid = null,
        ?string $expectedPackageId = null,
        ?string $expectedPlatform = null,
    ): CustomerAppRegistration
    {
        $now ??= CarbonImmutable::now('UTC');
        if (($envelope['algorithm'] ?? null) !== self::ALGORITHM
            || ! is_array($envelope['manifest'] ?? null)
            || ! is_string($envelope['signature'] ?? null)) {
            throw new CustomerAppAuthorizationException('MANIFEST_INVALID', 'Bootstrap manifest envelope is invalid.');
        }

        $manifest = $envelope['manifest'];
        $required = ['schema_version', 'manifest_id', 'app_uuid', 'package_id', 'platform', 'api_origin', 'branding_revision', 'issued_at', 'expires_at'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $manifest)) {
                throw new CustomerAppAuthorizationException('MANIFEST_INVALID', "Bootstrap manifest is missing {$field}.");
            }
        }

        if ((int) $manifest['schema_version'] !== self::SCHEMA_VERSION
            || ! Str::isUuid((string) $manifest['manifest_id'])
            || ! Str::isUuid((string) $manifest['app_uuid'])
            || ! in_array($manifest['platform'], [CustomerAppRegistration::PLATFORM_ANDROID, CustomerAppRegistration::PLATFORM_IOS], true)
            || ! hash_equals(rtrim((string) config('saas.customer_app.api_origin'), '/'), (string) $manifest['api_origin'])) {
            throw new CustomerAppAuthorizationException('MANIFEST_INVALID', 'Bootstrap manifest contract is invalid.');
        }

        if (($expectedAppUuid !== null && ! hash_equals($expectedAppUuid, (string) $manifest['app_uuid']))
            || ($expectedPackageId !== null && ! hash_equals($expectedPackageId, (string) $manifest['package_id']))
            || ($expectedPlatform !== null && ! hash_equals($expectedPlatform, (string) $manifest['platform']))) {
            throw new CustomerAppAuthorizationException('MANIFEST_IDENTITY_MISMATCH', 'Bootstrap manifest does not match this application.');
        }

        $publicKey = openssl_pkey_get_public($this->key('manifest_public_key'));
        $signature = $this->base64UrlDecode($envelope['signature']);
        if (! $publicKey || $signature === false
            || openssl_verify($this->canonical($manifest), $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new CustomerAppAuthorizationException('MANIFEST_SIGNATURE_INVALID', 'Bootstrap manifest signature is invalid.');
        }

        try {
            $issuedAt = CarbonImmutable::parse($manifest['issued_at'])->utc();
            $expiresAt = CarbonImmutable::parse($manifest['expires_at'])->utc();
        } catch (\Throwable) {
            throw new CustomerAppAuthorizationException('MANIFEST_INVALID', 'Bootstrap manifest timestamps are invalid.');
        }
        $skew = max(0, (int) config('saas.customer_app.clock_skew_seconds', 30));
        $maximumLifetime = max(30, (int) config('saas.customer_app.manifest_ttl_seconds', 300)) + $skew;
        if ($issuedAt->greaterThan($now->addSeconds($skew))
            || ! $expiresAt->greaterThan($now)
            || ! $expiresAt->greaterThan($issuedAt)
            || $issuedAt->diffInSeconds($expiresAt) > $maximumLifetime) {
            throw new CustomerAppAuthorizationException('MANIFEST_EXPIRED', 'Bootstrap manifest has expired or is not yet valid.');
        }

        $registration = CustomerAppRegistration::query()->withoutGlobalScopes()
            ->with('tenant')
            ->where('uuid', (string) $manifest['app_uuid'])->first();
        if (! $registration
            || $registration->status !== CustomerAppRegistration::STATUS_ACTIVE
            || ! hash_equals($registration->package_id, (string) $manifest['package_id'])
            || ! hash_equals($registration->platform, (string) $manifest['platform'])
            || (int) $registration->branding_revision !== (int) $manifest['branding_revision']) {
            throw new CustomerAppAuthorizationException('MANIFEST_STALE', 'Bootstrap manifest is revoked or no longer current.');
        }

        $this->assertRegistrationIsUsable($registration);

        $replayKey = 'customer-app:manifest:'.hash('sha256', (string) $manifest['manifest_id']);
        $replayTtl = max(1, $now->diffInSeconds($expiresAt, false));
        if (! Cache::add($replayKey, true, $replayTtl)) {
            throw new CustomerAppAuthorizationException('MANIFEST_REPLAYED', 'Bootstrap manifest has already been consumed.');
        }

        return $registration;
    }

    private function assertRegistrationIsUsable(CustomerAppRegistration $registration): void
    {
        if ($registration->status !== CustomerAppRegistration::STATUS_ACTIVE) {
            throw new CustomerAppAuthorizationException('APP_INACTIVE', 'Customer application registration is not active.');
        }
        if (! $registration->tenant || $registration->tenant->trashed() || ! $registration->tenant->is_active) {
            throw new CustomerAppAuthorizationException('TENANT_SUSPENDED', 'Restaurant access is suspended.');
        }

        $this->entitlements->assertEnabled($registration->tenant);
    }

    private function canonical(array $manifest): string
    {
        $ordered = [];
        foreach (['schema_version', 'manifest_id', 'app_uuid', 'package_id', 'platform', 'api_origin', 'branding_revision', 'issued_at', 'expires_at'] as $key) {
            $ordered[$key] = $manifest[$key] ?? null;
        }

        return json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function key(string $name): string
    {
        $value = (string) config("saas.customer_app.{$name}");
        if ($value !== '' && is_file($value) && is_readable($value)) {
            return (string) file_get_contents($value);
        }

        return str_replace('\\n', "\n", $value);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string|false
    {
        $padding = (4 - strlen($value) % 4) % 4;
        return base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);
    }
}
