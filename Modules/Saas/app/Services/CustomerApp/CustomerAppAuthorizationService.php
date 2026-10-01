<?php

namespace Modules\Saas\Services\CustomerApp;

use Modules\Branch\Models\Branch;
use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Models\CustomerAppRegistration;
use Modules\Saas\Models\CustomerAppSetting;
use Modules\Saas\Support\CustomerAppEntitlement;
use Modules\Saas\Models\Tenant;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class CustomerAppAuthorizationService
{
    public function __construct(private readonly CustomerAppEntitlementService $entitlements)
    {
    }

    public function resolve(
        string $appUuid,
        string $packageId,
        string $platform,
        ?int $branchId = null,
        ?User $customer = null,
        ?int $clientTenantId = null,
        string $requiredEntitlement = CustomerAppEntitlement::APP,
    ): CustomerAppRegistration {
        $registration = CustomerAppRegistration::query()
            ->withoutGlobalScopes()
            ->with('tenant')
            ->where('uuid', $appUuid)
            ->first();

        if (! $registration) {
            throw new CustomerAppAuthorizationException('APP_NOT_FOUND', 'Customer application registration was not found.', 404);
        }
        return $this->validateRegistration(
            $registration,
            $packageId,
            $platform,
            $branchId,
            $customer,
            $clientTenantId,
            $requiredEntitlement,
        );
    }

    public function validateRegistration(
        CustomerAppRegistration $registration,
        string $packageId,
        string $platform,
        ?int $branchId = null,
        ?User $customer = null,
        ?int $clientTenantId = null,
        string $requiredEntitlement = CustomerAppEntitlement::APP,
    ): CustomerAppRegistration {
        $registration->loadMissing('tenant');
        if ($registration->status !== CustomerAppRegistration::STATUS_ACTIVE) {
            throw new CustomerAppAuthorizationException('APP_INACTIVE', 'Customer application registration is not active.');
        }
        if (! $registration->tenant || $registration->tenant->trashed() || ! $registration->tenant->is_active) {
            throw new CustomerAppAuthorizationException('TENANT_SUSPENDED', 'Restaurant access is suspended.');
        }
        if (! hash_equals($registration->package_id, $packageId)) {
            throw new CustomerAppAuthorizationException('PACKAGE_MISMATCH', 'Application package identity does not match.');
        }
        if (! hash_equals($registration->platform, strtolower($platform))) {
            throw new CustomerAppAuthorizationException('PLATFORM_MISMATCH', 'Application platform does not match.');
        }
        if ($clientTenantId !== null && $clientTenantId !== (int) $registration->tenant_id) {
            throw new CustomerAppAuthorizationException('TENANT_MISMATCH', 'Client tenant identity is not authoritative.');
        }
        if ($customer && (int) $customer->tenant_id !== (int) $registration->tenant_id) {
            throw new CustomerAppAuthorizationException('TENANT_MISMATCH', 'Customer token belongs to another restaurant.');
        }
        if ($branchId !== null && ! Branch::query()->withoutGlobalScopes()
            ->whereKey($branchId)->where('tenant_id', $registration->tenant_id)->exists()) {
            throw new CustomerAppAuthorizationException('BRANCH_FORBIDDEN', 'Branch does not belong to this restaurant.');
        }

        $this->entitlements->assertEnabled($registration->tenant, $requiredEntitlement);
        $this->assertRuntimeEnabled($registration->tenant);

        return $registration;
    }

    public function assertBuildAccess(User $administrator, Tenant $tenant, bool $aab = false): void
    {
        if ($tenant->trashed() || ! $tenant->is_active) {
            throw new CustomerAppAuthorizationException('TENANT_SUSPENDED', 'Restaurant access is suspended.');
        }

        $platformAdministrator = $administrator->isSuperAdmin() && $administrator->can('admin.saas.manage');
        $tenantAdministrator = (int) $administrator->tenant_id === (int) $tenant->id
            && $administrator->hasRole(DefaultRole::EnterpriseAdmin->value);

        if (! $platformAdministrator && ! $tenantAdministrator) {
            throw new CustomerAppAuthorizationException('ROLE_FORBIDDEN', 'A tenant-wide administrator is required.');
        }

        $this->entitlements->assertEnabled($tenant, CustomerAppEntitlement::APP);
        $this->assertRuntimeEnabled($tenant);
        $this->entitlements->assertEnabled($tenant, CustomerAppEntitlement::BUILD);
        if ($aab) {
            $this->entitlements->assertEnabled($tenant, CustomerAppEntitlement::AAB);
        }
    }

    private function assertRuntimeEnabled(Tenant $tenant): void
    {
        $enabled = CustomerAppSetting::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->value('app_enabled');

        if ($enabled !== null && ! filter_var($enabled, FILTER_VALIDATE_BOOL)) {
            throw new CustomerAppAuthorizationException('APP_PAUSED', 'Customer application access is paused for this restaurant.', 403);
        }
    }
}
