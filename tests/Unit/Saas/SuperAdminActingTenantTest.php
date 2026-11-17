<?php

namespace Tests\Unit\Saas;

use Tests\TestCase;

/**
 * The platform Super Admin works inside a tenant chosen in the console
 * (X-Tenant-ID). These contracts keep that access from widening into a
 * cross-tenant hole for anyone else.
 */
class SuperAdminActingTenantTest extends TestCase
{
    private function source(string $path): string
    {
        $source = file_get_contents(base_path($path));
        $this->assertIsString($source, $path);

        return $source;
    }

    public function test_only_the_platform_super_admin_can_choose_a_tenant(): void
    {
        $acting = $this->source('Modules/Saas/app/Support/ActingTenant.php');

        $this->assertStringContainsString("\$request->header('X-Tenant-ID')", $acting);
        // Gate: no domain tenant yet, a header, and the platform Super Admin.
        $this->assertStringContainsString('$this->context->get() !== null', $acting);
        $this->assertStringContainsString('! $user->isSuperAdmin()', $acting);
        $this->assertStringContainsString('$user instanceof FlightCustomer', $acting);
        // An unknown tenant is refused instead of silently falling back.
        $this->assertStringContainsString("'ACTING_TENANT_NOT_FOUND'", $acting);
        // Applied like a tenant domain: context, attributes, tenant settings.
        $this->assertStringContainsString('$this->context->set($acting);', $acting);
        $this->assertStringContainsString("\$request->attributes->set('tenant_id', \$acting->id);", $acting);
        $this->assertStringContainsString("\$request->attributes->set('acting_tenant_id', \$acting->id);", $acting);
        $this->assertStringContainsString("app()->singleton('setting'", $acting);
    }

    public function test_the_choice_applies_to_signed_in_and_public_flight_routes(): void
    {
        $middleware = $this->source('Modules/Saas/app/Http/Middleware/EnsureAuthenticatedTenant.php');
        $public = $this->source('Modules/Saas/app/Http/Middleware/ApplyActingTenant.php');
        $routes = $this->source('Modules/Flight/routes/api/v1.php');

        $this->assertStringContainsString('$this->actingTenant->apply($request, $user)', $middleware);
        $this->assertStringContainsString('$this->actingTenant->apply($request, $user)', $public);
        // The catalog/meta group skips EnsureAuthenticatedTenant, so it runs ApplyActingTenant.
        $this->assertStringContainsString("Route::withoutMiddleware(['auth', EnsureAuthenticatedTenant::class])->middleware(ApplyActingTenant::class)", $routes);
    }

    public function test_super_admin_check_excludes_tenant_and_branch_users(): void
    {
        $roles = $this->source('Modules/User/app/Traits/HasRoles.php');

        $this->assertStringContainsString('$this->tenantId() === null', $roles);
        $this->assertStringContainsString('$this->branchId() === null', $roles);
    }

    public function test_platform_endpoints_ignore_the_chosen_tenant(): void
    {
        $acting = $this->source('Modules/Saas/app/Support/ActingTenant.php');

        foreach (["'api/v1/saas/*'", "'api/v1/tenants'", "'api/v1/tenant-subscriptions'", "'api/v1/subscription-plans'"] as $path) {
            $this->assertStringContainsString($path, $acting);
        }
        // "tenants/current" is the tenant being worked in, not the platform registry.
        $this->assertStringContainsString("! \$request->is('api/v1/tenants/current', 'api/v1/tenants/current/*')", $acting);
    }

    public function test_tenant_scope_limits_the_super_admin_to_the_chosen_tenant(): void
    {
        $scope = $this->source('Modules/Saas/app/Traits/BelongsToTenant.php');

        // Filtered to the chosen tenant, and new rows are stamped with it.
        $this->assertStringContainsString("\$user->isSuperAdmin() && (\$actingTenantId = self::actingTenantId())", $scope);
        $this->assertStringContainsString("\$model->tenant_id = \$actingTenantId;", $scope);
        $this->assertStringContainsString("request()->attributes->get('acting_tenant_id')", $scope);
        // Tenant users are still pinned to their own tenant.
        $this->assertStringContainsString('$user->tenantId()', $scope);
    }

    public function test_tenant_level_controllers_use_the_chosen_tenant_for_the_super_admin(): void
    {
        foreach ([
            'Modules/Payment/app/Http/Controllers/Api/V1/TenantPaymentGatewayController.php',
            'Modules/Flight/app/Http/Controllers/Api/V1/FlightSubscriptionController.php',
            'Modules/Flight/app/Http/Controllers/Api/V1/FlightOfferAdminController.php',
            'Modules/Flight/app/Http/Controllers/Api/V1/FlightFareFamilyController.php',
            'Modules/Flight/app/Http/Controllers/Api/V1/FlightCommissionRuleController.php',
            'Modules/Flight/app/Http/Controllers/Api/V1/FlightWalletController.php',
            'Modules/Flight/app/Http/Controllers/Api/V1/FlightSettlementController.php',
        ] as $path) {
            $source = $this->source($path);
            // Own tenant first, then the chosen tenant: a tenant user can never
            // be moved to another tenant by the attribute.
            $this->assertMatchesRegularExpression(
                "/tenant(_id|Id\\(\\))[^;]*\\?\\?[^;]*attributes->get\\('tenant_id'\\)/",
                $source,
                $path
            );
        }
    }
}
