<?php

namespace Modules\Saas\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Saas\Models\Tenant;

class TenantDomainResolver
{
    public function resolve(Request $request): ?Tenant
    {
        $candidate = $this->candidateHost($request);

        if ($candidate === null || $this->isCentralHost($candidate)) {
            return null;
        }

        $slug = $this->subdomainSlug($candidate);

        return Tenant::query()
            ->withoutGlobalScopes()
            ->where('is_active', true)
            ->where(function ($query) use ($candidate, $slug) {
                $query->where('domain', $candidate);

                if ($slug !== null) {
                    $query->orWhere('slug', $slug);
                }
            })
            ->first();
    }

    private function candidateHost(Request $request): ?string
    {
        $headerHost = $this->normalizeHost($request->header('X-NexDine-Tenant-Domain'));
        $originHost = $this->normalizeHost($this->hostFromUrl($request->headers->get('Origin')));
        $refererHost = $this->normalizeHost($this->hostFromUrl($request->headers->get('Referer')));

        foreach ([$originHost, $refererHost] as $trustedBrowserHost) {
            if ($trustedBrowserHost !== null) {
                abort_if(
                    $headerHost !== null && $headerHost !== $trustedBrowserHost,
                    400,
                    'Tenant domain header does not match request origin.'
                );

                return $trustedBrowserHost;
            }
        }

        foreach ([$headerHost, $request->getHost()] as $host) {
            $normalized = $this->normalizeHost($host);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function hostFromUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        return parse_url($url, PHP_URL_HOST) ?: null;
    }

    private function normalizeHost(?string $host): ?string
    {
        if (blank($host)) {
            return null;
        }

        $host = Str::lower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?: $host;

        return $host === '' ? null : $host;
    }

    private function isCentralHost(string $host): bool
    {
        return in_array($host, $this->centralHosts(), true);
    }

    private function centralHosts(): array
    {
        $hosts = array_merge(
            (array) config('saas.central_domains', []),
            [
                parse_url(config('app.url'), PHP_URL_HOST),
                config('core.routes.api.domain'),
                config('core.routes.public.domain'),
            ]
        );

        return collect($hosts)
            ->filter()
            ->map(fn(string $host) => $this->normalizeHost($host))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function subdomainSlug(string $host): ?string
    {
        $rootDomain = $this->normalizeHost(config('saas.root_domain'));

        if ($rootDomain === null || !Str::endsWith($host, ".{$rootDomain}")) {
            return null;
        }

        $slug = Str::before($host, ".{$rootDomain}");

        return $slug === '' || Str::contains($slug, '.') ? null : $slug;
    }
}
