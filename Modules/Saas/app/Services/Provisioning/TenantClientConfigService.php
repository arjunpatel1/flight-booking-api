<?php

namespace Modules\Saas\Services\Provisioning;

use Modules\Branch\Models\Branch;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Illuminate\Support\Facades\URL;

class TenantClientConfigService
{
    public function __construct(
        private readonly EffectiveTenantEntitlementService $entitlements,
    ) {
    }

    public function config(Tenant $tenant): array
    {
        $branch = Branch::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_main', true)
            ->first();

        $theme = $tenant->settings['theme'] ?? [];
        $apiUrl = $this->apiUrl();
        $tenantDomain = $this->validDomain((string) $tenant->domain);
        $tenantOrigin = $tenantDomain ? "https://{$tenantDomain}" : null;
        $reverbHost = $this->reverbHost();
        $publicReverb = $this->isPublicHost($reverbHost);
        $apiOrigin = $this->apiOrigin();

        return [
            'version' => 2,
            'branding_revision' => hash('sha256', json_encode([
                $tenant->updated_at?->timestamp,
                $tenant->settings,
            ])),
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenantDomain,
            ],
            'branch' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
                'currency' => $branch->currency,
                'timezone' => $branch->timezone,
            ] : null,
            'app' => [
                'name' => 'Waiter',
                'company_name' => $tenant->name,
                'api_base_url' => $apiUrl,
                'restaurant_url' => $tenantOrigin,
                'activation_url' => null,
            ],
            'branding' => [
                'logo_url' => $tenant->settings['logo_url'] ?? null,
                'splash_logo_url' => $tenant->settings['splash_logo_url'] ?? null,
                'app_icon_url' => $tenant->settings['app_icon_url'] ?? $tenant->settings['logo_url'] ?? null,
                'primary_color' => $theme['primary'] ?? '#ff6b00',
                'secondary_color' => $theme['secondary'] ?? '#0f172a',
                'accent_color' => $theme['accent'] ?? '#ff6b00',
                'background_color' => $theme['background'] ?? '#f8fafc',
                'surface_color' => $theme['surface'] ?? '#ffffff',
            ],
            'reverb' => [
                'app_key' => config('reverb.apps.apps.0.key', env('REVERB_APP_KEY', '')),
                'host' => $reverbHost,
                'port' => $publicReverb ? '443' : (string) env('REVERB_PORT', '443'),
                'scheme' => $publicReverb ? 'https' : env('REVERB_SCHEME', 'https'),
                'origin' => $apiOrigin ?: $tenantOrigin ?: config('app.url'),
            ],
            'features' => [
                'offline_orders' => true,
                'runtime_branding' => true,
                'white_label_build_optional' => true,
            ],
            'entitlements' => $this->entitlements->features($tenant),
            'downloads' => $this->downloads(),
        ];
    }

    public function activationPayload(Tenant $tenant): array
    {
        $branch = Branch::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_main', true)
            ->first();
        $issuedAt = now();
        $expiresAt = $issuedAt->copy()->addMinutes((int) config('saas.self_service.activation_ttl_minutes', 60));
        $relativeConfigUrl = URL::temporarySignedRoute(
            'api.v1.saas.client-config.signed',
            $expiresAt,
            ['slug' => $tenant->slug],
            absolute: false,
        );
        $apiParts = parse_url($this->apiUrl());
        $apiOrigin = ($apiParts['scheme'] ?? 'https').'://'.($apiParts['host'] ?? '');
        if (isset($apiParts['port'])) {
            $apiOrigin .= ':'.$apiParts['port'];
        }
        $configUrl = rtrim($apiOrigin, '/').$relativeConfigUrl;
        $payload = "{$tenant->id}|{$tenant->slug}|{$configUrl}|{$expiresAt->timestamp}";

        return [
            'type' => 'nexdine_waiter_activation',
            'version' => 2,
            'tenant' => $tenant->slug,
            'tenant_id' => $tenant->id,
            'branch_id' => $branch?->id,
            'config_url' => $configUrl,
            'issued_at' => $issuedAt->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'signature' => hash_hmac('sha256', $payload, (string) config('app.key')),

            // --- Runtime metadata (Phase 2.6) -------------------------------
            // Additive and unsigned: these are runtime *hints*, not security
            // claims (the signed config_url remains the trust anchor). Old
            // clients ignore unknown keys; old payloads that lack these keys
            // are read by new clients with safe defaults (v1 / shared), so
            // both directions stay backward compatible.
            //
            // tenant_uuid / branch_uuid use the stable slug / id today. The
            // keys are named for the future so a real uuid can be swapped in
            // without a client change.
            'tenant_uuid' => $tenant->slug,
            'branch_uuid' => $branch?->id,
            'realtime_version' => (string) config('saas.realtime.channel_version', 'v1'),
            'api_version' => 'v1',
            'runtime_version' => (string) config('saas.runtime.version', '1'),
            'database_mode' => $this->databaseModeFor($tenant),
        ];
    }

    private function apiUrl(): string
    {
        return rtrim((string) config('saas.self_service.public_api_base_url'), '/');
    }

    private function apiOrigin(): ?string
    {
        $parts = parse_url($this->apiUrl());
        $host = $parts['host'] ?? null;
        if (! $host) {
            return null;
        }

        $origin = ($parts['scheme'] ?? 'https').'://'.$host;
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    private function reverbHost(): string
    {
        return (string) (
            env('REVERB_HOST')
            ?: parse_url($this->apiUrl(), PHP_URL_HOST)
            ?: parse_url(config('app.url'), PHP_URL_HOST)
            ?: ''
        );
    }

    private function isPublicHost(string $host): bool
    {
        $host = trim(strtolower($host));

        return $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)
            && ! str_ends_with($host, '.test')
            && ! str_ends_with($host, '.localhost')
            && str_contains($host, '.');
    }

    /**
     * The tenant's hosting mode from the Phase-1 infrastructure registry.
     * Absent registry row → 'shared' (the truthful default today), so this is
     * safe before any tenant has been moved and before the registry migration
     * has even run.
     */
    private function databaseModeFor(Tenant $tenant): string
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('saas_tenant_infrastructure')) {
            return 'shared';
        }

        $mode = \Modules\Saas\Models\TenantInfrastructure::query()
            ->where('tenant_id', $tenant->id)
            ->value('mode');

        return in_array($mode, ['shared', 'dedicated', 'cluster'], true) ? $mode : 'shared';
    }

    private function validDomain(string $domain): ?string
    {
        $domain = trim(strtolower($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = preg_replace('#/.*$#', '', $domain) ?? $domain;
        $domain = trim($domain, ". \t\n\r\0\x0B");

        return preg_match('/^(?!-)(?:[a-z0-9-]{1,63}\.)+[a-z]{2,63}$/', $domain)
            ? $domain
            : null;
    }

    private function downloads(): array
    {
        return collect(config('saas.self_service.downloads', []))
            ->filter()
            ->map(fn (string $url, string $key) => [
                'key' => $key,
                'url' => $url,
            ])
            ->values()
            ->all();
    }
}
