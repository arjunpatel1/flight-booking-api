<?php

namespace Modules\Saas\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Saas\Support\TenantContext;
use Modules\Saas\Support\TenantDomainResolver;
use Modules\Setting\Models\Setting;
use Modules\Setting\Repositories\SettingRepository;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantFromDomain
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantDomainResolver $resolver,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return response()->noContent();
        }

        $tenant = $this->resolver->resolve($request);

        $this->context->set($tenant);
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('tenant_id', $tenant?->id);
        $this->rebindSettings();

        try {
            if ($tenant && ! $tenant->is_active) {
                $message = __('user::messages.tenant_suspended');

                if ($request->expectsJson() || $request->is('api/*')) {
                    return ApiResponse::errors(
                        ['code' => 'TENANT_SUSPENDED'],
                        $message,
                        Response::HTTP_FORBIDDEN
                    );
                }

                return response()->view('saas::tenant-unavailable', [
                    'tenant' => $tenant,
                    'reason' => 'suspended',
                    'message' => $message,
                ], Response::HTTP_FORBIDDEN);
            }

            return $next($request);
        } finally {
            // Octane and queue-style long-running processes reuse the
            // application container. Never allow one request's tenant to
            // become the implicit tenant of the next request.
            $this->context->clear();
            $this->rebindSettings();
        }
    }

    /**
     * The setting repository is registered as a singleton by the legacy core
     * provider. Long-running workers must not retain the previous hostname's
     * tenant-scoped settings.
     */
    private function rebindSettings(): void
    {
        app()->forgetInstance('setting');
        app()->singleton('setting', fn () => new SettingRepository(Setting::allCached()));
    }
}
