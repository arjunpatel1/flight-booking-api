<?php

namespace Tests\Unit\Saas;

use Illuminate\Routing\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Str;
use Modules\Saas\Http\Middleware\EnsurePlatformActor;
use Modules\User\Enums\DefaultRole;
use Modules\User\Facades\Permission;
use Tests\TestCase;

class SaasControlPlanePermissionTest extends TestCase
{
    public function test_platform_boundary_rejects_tenant_identities_even_if_rbac_is_misconfigured(): void
    {
        $request = Request::create('/api/v1/saas/security-center');
        $request->setUserResolver(fn () => new class {
            public function assignedToTenant(): bool
            {
                return true;
            }

            public function assignedToBranch(): bool
            {
                return false;
            }
        });

        $response = (new EnsurePlatformActor())->handle($request, fn () => response()->noContent());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('CONTROL_PLANE_FORBIDDEN', (string) $response->getContent());
    }

    public function test_enterprise_admin_is_tenant_safe_and_keeps_full_restaurant_administration(): void
    {
        $this->assertContains(DefaultRole::EnterpriseAdmin->value, DefaultRole::getBranchAvailableRoles());

        // The restaurant owner is not the platform owner.
        $this->assertNotSame('*', DefaultRole::EnterpriseAdmin->getPermissions());

        $granted = DefaultRole::expandPermissions(
            DefaultRole::EnterpriseAdmin->getPermissions(),
            Permission::getPermissionNames()
        );

        // It administers its own restaurant end to end. These modules were lost
        // when the role was pinned to the branch-admin subset.
        foreach ([
            'admin.branches.index',
            'admin.branches.create',
            'admin.media.index',
            'admin.translations.edit',
            'admin.currency_rates.edit',
            'admin.appearance.edit',
            'admin.printer_assignments.index',
            'admin.reasons.create',
            'admin.units.create',
            'admin.loyalty_programs.create',
            'admin.whatsapp.settings',
            'admin.cart.index',
        ] as $permission) {
            $this->assertContains($permission, $granted, "Tenant owner lost {$permission}.");
        }

        // It never reaches the control plane, whatever else it gains.
        foreach ($granted as $permission) {
            $this->assertFalse(
                Str::startsWith($permission, DefaultRole::platformOnlyPermissionPrefixes()),
                "Tenant owner must never hold control-plane permission {$permission}."
            );
        }
    }

    public function test_sensitive_control_plane_routes_require_manage_or_destroy_permissions(): void
    {
        $expectations = [
            'api/v1/saas/device-center/assign' => 'can:admin.saas.manage',
            'api/v1/saas/tenants/{tenant}/complete-onboarding' => 'can:admin.saas.manage',
            'api/v1/saas/tenants/{tenant}/sync-permissions' => 'can:admin.saas.manage',
            'api/v1/saas/tenants/{tenant}/users/{user}/role' => 'can:admin.saas.manage',
            'api/v1/saas/communication-center/templates' => 'can:admin.saas.manage',
            'api/v1/saas/communication-center/campaigns' => 'can:admin.saas.manage',
            'api/v1/saas/communication-center/company-mail' => 'can:admin.saas.manage',
            'api/v1/saas/security-center/tokens/{token}/revoke' => 'can:admin.saas.manage',
            'api/v1/saas/security-center/users/{user}/revoke-sessions' => 'can:admin.saas.manage',
            'api/v1/saas/billing/invoices/{invoice}/refund' => 'can:admin.saas.manage',
            'api/v1/saas/tenants/bulk/apply-coupon' => 'can:admin.saas.manage',
            'api/v1/saas/tenants/bulk/archive' => 'can:admin.tenants.destroy',
            'api/v1/saas/tenants/{tenant}/apps/waiter/activation' => 'can:admin.saas.manage',
        ];

        foreach ($expectations as $uri => $middleware) {
            $route = collect(RouteFacade::getRoutes())->first(fn (Route $route) => $route->uri() === $uri);
            $this->assertNotNull($route, "Missing route {$uri}");
            $this->assertContains($middleware, $route->gatherMiddleware(), "{$uri} does not require {$middleware}");
            $this->assertContains(EnsurePlatformActor::class, $route->gatherMiddleware(), "{$uri} is missing its platform identity boundary");
            $resolved = app('router')->gatherRouteMiddleware($route);
            $this->assertContains(Authenticate::class, $resolved, "{$uri} is not authenticated");
        }
    }

    public function test_tenant_customer_app_builds_require_settings_permission_and_entitlement(): void
    {
        $route = collect(RouteFacade::getRoutes())->first(fn (Route $route) =>
            $route->uri() === 'api/v1/tenants/current/customer-app/builds'
            && in_array('POST', $route->methods(), true)
        );

        $this->assertNotNull($route);
        $this->assertContains('can:admin.settings.edit', $route->gatherMiddleware());
        $this->assertContains('tenant.feature:customer_app', $route->gatherMiddleware());
    }

    public function test_read_surfaces_do_not_grant_tenant_users_control_plane_access(): void
    {
        foreach ([
            'api/v1/saas/dashboard',
            'api/v1/saas/operations-center',
            'api/v1/saas/communication-center',
            'api/v1/saas/security-center',
            'api/v1/saas/device-center',
            'api/v1/saas/tenants/{tenant}/access',
        ] as $uri) {
            $route = collect(RouteFacade::getRoutes())->first(fn (Route $route) => $route->uri() === $uri);
            $this->assertNotNull($route, "Missing route {$uri}");
            $this->assertContains('can:admin.saas.index', $route->gatherMiddleware());
            $this->assertContains(EnsurePlatformActor::class, $route->gatherMiddleware());
        }
    }
}
