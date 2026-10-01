<?php

namespace Modules\Saas\Services\CustomerApp;

use Illuminate\Support\Str;
use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Models\CustomerAppBuild;

final class CustomerAppBuildSnapshotValidator
{
    private const PACKAGE_PATTERN = '/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){2,}\z/';

    private const VERSION_PATTERN = '/\A\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/';

    private const TOOLCHAIN_PATTERN = '/\A[0-9A-Za-z.+_-]+\z/';

    private const COLOR_PATTERN = '/\A#[0-9A-Fa-f]{6}\z/';

    public function validate(array $snapshot): array
    {
        $platform = strtolower((string) data_get($snapshot, 'application.platform'));
        $buildType = strtolower((string) data_get($snapshot, 'build.type'));
        $repository = trim((string) data_get($snapshot, 'source.repository'));
        $apiOrigin = rtrim(trim((string) data_get($snapshot, 'api_origin')), '/');
        $displayName = trim((string) data_get($snapshot, 'branding.display_name'));

        $valid = $this->hasExpectedShape($snapshot)
            && (int) ($snapshot['schema_version'] ?? 0) === 2
            && Str::isUuid((string) data_get($snapshot, 'tenant.uuid'))
            && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', (string) data_get($snapshot, 'tenant.slug')) === 1
            && Str::isUuid((string) data_get($snapshot, 'application.uuid'))
            && preg_match(self::PACKAGE_PATTERN, (string) data_get($snapshot, 'application.package_id')) === 1
            && in_array($platform, ['android', 'ios'], true)
            && in_array($buildType, ['release', 'app_bundle'], true)
            && ! ($platform === 'ios' && $buildType === 'app_bundle')
            && preg_match(self::VERSION_PATTERN, (string) data_get($snapshot, 'build.version')) === 1
            && $this->isSafeDisplayName($displayName)
            && $this->isPublicHttpsUrl((string) data_get($snapshot, 'branding.logo_url'))
            && $this->isPublicHttpsUrl((string) data_get($snapshot, 'branding.app_icon_url'))
            && $this->isOptionalHttpsUrl(data_get($snapshot, 'branding.splash_logo_url'))
            && preg_match(self::COLOR_PATTERN, (string) data_get($snapshot, 'branding.primary_color')) === 1
            && preg_match(self::COLOR_PATTERN, (string) data_get($snapshot, 'branding.secondary_color')) === 1
            && (int) data_get($snapshot, 'branding_revision') >= 1
            && $this->isHttpsOrigin($apiOrigin)
            && $this->isHttpsUrl($repository)
            && preg_match('/\A[0-9a-f]{40}\z/', strtolower((string) data_get($snapshot, 'source.commit'))) === 1
            && preg_match(self::TOOLCHAIN_PATTERN, (string) data_get($snapshot, 'toolchain.flutter')) === 1
            && ($platform !== 'android'
                || (preg_match(self::TOOLCHAIN_PATTERN, (string) data_get($snapshot, 'toolchain.android_sdk')) === 1
                    && preg_match('/\A[0-9a-f]{64}\z/', strtolower((string) data_get($snapshot, 'signing.certificate_sha256'))) === 1));

        if (! $valid
            || ! $this->matchesConfiguredValue($repository, 'saas.customer_app_build.repository')
            || ! $this->matchesConfiguredValue($apiOrigin, 'saas.customer_app.api_origin', trimTrailingSlash: true)
            || ! $this->matchesConfiguredValue(
                strtolower((string) data_get($snapshot, 'source.commit')),
                'saas.customer_app_build.source_commit',
                lowercase: true,
            )
            || ! $this->matchesConfiguredValue(
                (string) data_get($snapshot, 'toolchain.flutter'),
                'saas.customer_app_build.flutter_version',
            )
            || ($platform === 'android' && ! $this->matchesConfiguredValue(
                (string) data_get($snapshot, 'toolchain.android_sdk'),
                'saas.customer_app_build.android_sdk_version',
            ))
            || ($platform === 'android' && ! $this->matchesConfiguredValue(
                strtolower((string) data_get($snapshot, 'signing.certificate_sha256')),
                'saas.customer_app_build.signer_sha256',
                lowercase: true,
            ))) {
            throw new CustomerAppAuthorizationException(
                'BUILD_SNAPSHOT_INVALID',
                'The immutable build snapshot is invalid or does not match the controlled build configuration.',
                422,
            );
        }

        data_set($snapshot, 'application.platform', $platform);
        data_set($snapshot, 'build.type', $buildType);
        data_set($snapshot, 'branding.display_name', $displayName);
        data_set($snapshot, 'api_origin', $apiOrigin);
        data_set($snapshot, 'source.repository', $repository);
        data_set($snapshot, 'source.commit', strtolower((string) data_get($snapshot, 'source.commit')));
        data_set($snapshot, 'signing.certificate_sha256', strtolower((string) data_get($snapshot, 'signing.certificate_sha256')));

        return $snapshot;
    }

    private function hasExpectedShape(array $snapshot): bool
    {
        return $this->hasExactKeys($snapshot, ['schema_version', 'tenant', 'application', 'build', 'branding', 'branding_revision', 'api_origin', 'source', 'signing', 'toolchain'])
            && $this->hasExactKeys((array) ($snapshot['tenant'] ?? []), ['uuid', 'slug'])
            && $this->hasExactKeys((array) ($snapshot['application'] ?? []), ['uuid', 'package_id', 'platform'])
            && $this->hasExactKeys((array) ($snapshot['build'] ?? []), ['type', 'version'])
            && $this->hasExactKeys((array) ($snapshot['branding'] ?? []), ['display_name', 'logo_url', 'app_icon_url', 'primary_color', 'secondary_color', 'splash_logo_url'])
            && $this->hasExactKeys((array) ($snapshot['source'] ?? []), ['repository', 'commit'])
            && $this->hasExactKeys((array) ($snapshot['signing'] ?? []), ['certificate_sha256'])
            && $this->hasExactKeys((array) ($snapshot['toolchain'] ?? []), ['flutter', 'android_sdk']);
    }

    private function hasExactKeys(array $actual, array $expected): bool
    {
        $actualKeys = array_keys($actual);
        $expectedKeys = $expected;
        sort($actualKeys);
        sort($expectedKeys);

        return $actualKeys === $expectedKeys;
    }

    public function revision(array $snapshot): string
    {
        return hash('sha256', json_encode($this->sortRecursive($this->validate($snapshot)), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Validate both the controlled snapshot schema and its immutable ownership
     * binding. A structurally valid snapshot is still unsafe when it was copied
     * from another tenant, registration, source revision, or signing request.
     */
    public function validateForBuild(CustomerAppBuild $build): array
    {
        $build->loadMissing(['tenant', 'registration']);
        $snapshot = $this->validate((array) $build->config_snapshot);
        $tenant = $build->tenant;
        $registration = $build->registration;

        $matches = $tenant !== null
            && $registration !== null
            && (int) $registration->tenant_id === (int) $build->tenant_id
            && $this->same((string) $tenant->uuid, (string) data_get($snapshot, 'tenant.uuid'))
            && $this->same((string) $tenant->slug, (string) data_get($snapshot, 'tenant.slug'))
            && $this->same((string) $registration->uuid, (string) data_get($snapshot, 'application.uuid'))
            && $this->same((string) $registration->package_id, (string) data_get($snapshot, 'application.package_id'))
            && $this->same((string) $build->platform, (string) data_get($snapshot, 'application.platform'))
            && $this->same((string) $build->build_type, (string) data_get($snapshot, 'build.type'))
            && $this->same((string) $build->requested_version, (string) data_get($snapshot, 'build.version'))
            && $this->same((string) $build->source_commit, (string) data_get($snapshot, 'source.commit'))
            && (int) $build->branding_revision === (int) data_get($snapshot, 'branding_revision')
            && $this->same((string) $build->build_config_revision, $this->revision($snapshot));

        if (! $matches) {
            throw new CustomerAppAuthorizationException(
                'BUILD_SNAPSHOT_INVALID',
                'The immutable build snapshot is not bound to this tenant application request.',
                422,
            );
        }

        return $snapshot;
    }

    private function isSafeDisplayName(string $value): bool
    {
        return $value !== ''
            && mb_strlen($value) <= 80
            && preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1;
    }

    private function isOptionalHttpsUrl(mixed $value): bool
    {
        return blank($value) || $this->isPublicHttpsUrl((string) $value);
    }

    private function isHttpsOrigin(string $value): bool
    {
        if (! $this->isHttpsUrl($value)) {
            return false;
        }

        $parts = parse_url($value);

        $path = (string) ($parts['path'] ?? '');

        return ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && ! str_contains($path, '..');
    }

    private function isHttpsUrl(string $value): bool
    {
        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($value);

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ! isset($parts['user'], $parts['pass'])
            && filled($parts['host'] ?? null);
    }

    /**
     * Branding assets may later be fetched by an isolated build worker. Keep
     * loopback, link-local and private network destinations out of the signed
     * build contract so the asset fields cannot become an SSRF primitive.
     */
    private function isPublicHttpsUrl(string $value): bool
    {
        if (! $this->isHttpsUrl($value)) {
            return false;
        }

        $host = strtolower(rtrim((string) parse_url($value, PHP_URL_HOST), '.'));
        if ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) === false
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function matchesConfiguredValue(
        string $snapshotValue,
        string $configKey,
        bool $lowercase = false,
        bool $trimTrailingSlash = false,
    ): bool {
        $configured = trim((string) config($configKey));
        if ($lowercase) {
            $configured = strtolower($configured);
        }
        if ($trimTrailingSlash) {
            $configured = rtrim($configured, '/');
        }

        return $configured !== '' && hash_equals($configured, $snapshotValue);
    }

    private function same(string $expected, string $actual): bool
    {
        return $expected !== '' && hash_equals($expected, $actual);
    }

    private function sortRecursive(array $value): array
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->sortRecursive($item);
            }
        }
        unset($item);

        return $value;
    }
}
