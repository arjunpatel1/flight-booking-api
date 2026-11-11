<?php

namespace Modules\User\Enums;

use Illuminate\Support\Str;
use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum DefaultRole: string
{
    use EnumArrayable, EnumTranslatable;

    /**
     * Every permission except the SaaS control plane. See expandPermissions().
     */
    public const TENANT_WILDCARD = '*:tenant';


    case SuperAdmin = "super_admin";
    case Admin = "admin";
    case EnterpriseAdmin = "enterprise_admin";
    case AdminBranch = "admin_branch";
    case Manager = "manager";
    case Cashier = "cashier";
    case Kitchen = "kitchen";
    case Waiter = "waiter";
    case Customer = "customer";
    case FlightAdmin = "flight_admin";
    case FlightDistributor = "flight_distributor";
    case FlightAgent = "flight_agent";
    case FlightCustomer = "flight_customer";

    /**
     * Get branch available roles
     *
     * @return array
     */
    public static function getBranchAvailableRoles(): array
    {
        return [
            DefaultRole::EnterpriseAdmin->value,
            DefaultRole::AdminBranch->value,
            DefaultRole::Manager->value,
            DefaultRole::Waiter->value,
            DefaultRole::Cashier->value,
            DefaultRole::Kitchen->value,
        ];
    }

    /**
     * Roles a platform admin may assign to a tenant's staff from the SaaS
     * tenant workspace: the branch roles plus the flight agency roles.
     * Kept separate from getBranchAvailableRoles(), which also drives the
     * tenant's own user form and role listings.
     *
     * @return array
     */
    public static function getTenantAssignableRoles(): array
    {
        return [
            ...self::getBranchAvailableRoles(),
            DefaultRole::FlightAdmin->value,
            DefaultRole::FlightAgent->value,
        ];
    }

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "user::enums.default_roles";
    }

    /**
     * Permission prefixes that belong to the SaaS control plane and must never be
     * granted to a tenant-scoped role. Everything outside this list is restaurant
     * functionality that a tenant owner is expected to administer for itself.
     *
     * @return array
     */
    public static function platformOnlyPermissionPrefixes(): array
    {
        return [
            'admin.saas.',
            'admin.tenants.',
            'admin.subscription_plans.',
            'admin.tenant_subscriptions.',
            'admin.system_configurations.',
        ];
    }

    /**
     * Expand a role's declared permission profile against the registry.
     *
     * Two wildcards exist. `*` is every permission in the system. TENANT_WILDCARD
     * is every permission except the SaaS control plane — the profile of a
     * restaurant owner, who administers all of its own restaurant but none of the
     * platform. Isolation between tenants is enforced by the branch/tenant global
     * scopes, so a tenant owner holding a restaurant permission still only ever
     * sees its own rows.
     *
     * @param array|string $permissions
     * @param array $allPermissions
     * @return array
     */
    public static function expandPermissions(array|string $permissions, array $allPermissions): array
    {
        if ($permissions === '*') {
            return array_values($allPermissions);
        }

        if ($permissions === self::TENANT_WILDCARD) {
            return array_values(array_filter(
                $allPermissions,
                fn(string $permission) => !Str::startsWith($permission, self::platformOnlyPermissionPrefixes())
            ));
        }

        return (array) $permissions;
    }

    /**
     * Permission areas (admin.<area>.*) a flight agency uses, besides every
     * admin.flight_* area. Everything else in the registry is a restaurant
     * module (POS, cart, tables, kitchen, menus, loyalty, payroll, ...).
     * Mirrored in the web app's src/utils/planFeatures.ts.
     *
     * @return array
     */
    public static function flightAgencyPermissionAreas(): array
    {
        // Not listed (restaurant sections the flight sidebar hides): dashboards,
        // customers, payments, invoices, discounts, reports, whatsapp (marketing).
        return [
            // Flight coupons and discounts are guarded by admin.vouchers.
            'vouchers',
            'users', 'roles', 'profiles', 'notifications', 'settings', 'appearance',
            'branches', 'activity_logs', 'authentication_logs', 'media', 'translations',
            'currency_rates', 'imports',
        ];
    }

    public static function isFlightAgencyPermission(string $permission): bool
    {
        $area = Str::of($permission)->after('admin.')->before('.')->toString();

        return Str::startsWith($area, 'flight') || in_array($area, self::flightAgencyPermissionAreas(), true);
    }

    /**
     * The permissions this role is synced to. The agency staff roles keep only
     * flight-agency permissions (no restaurant modules); the Tenant
     * Administrator also gets every flight permission so it can run the agency.
     *
     * @param array $allPermissions
     * @return array
     */
    public function effectivePermissions(array $allPermissions): array
    {
        $permissions = self::expandPermissions($this->getPermissions(), $allPermissions);

        if (! in_array($this, [self::EnterpriseAdmin, self::AdminBranch, self::Manager], true)) {
            return $permissions;
        }

        if ($this === self::AdminBranch) {
            $permissions = [
                ...$permissions,
                ...array_filter($allPermissions, fn (string $permission) => Str::startsWith($permission, 'admin.flight_')),
            ];
        }

        return array_values(array_unique(array_filter(
            $permissions,
            fn (string $permission) => self::isFlightAgencyPermission($permission),
        )));
    }

    /**
     * Get role permissions
     *
     * @return array|string
     */
    public function getPermissions(): array|string
    {
        $permissions = require __DIR__ . "/../../Resources/roles_permissions.php";

        return $permissions[$this->value] ?? [];
    }
}
