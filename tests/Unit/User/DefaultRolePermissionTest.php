<?php

namespace Tests\Unit\User;

use Modules\User\Enums\DefaultRole;
use PHPUnit\Framework\TestCase;

class DefaultRolePermissionTest extends TestCase
{
    public function test_all_nine_default_roles_have_an_explicit_access_contract(): void
    {
        $roles = collect(DefaultRole::cases())->keyBy(fn (DefaultRole $role) => $role->value);

        $this->assertSame([
            'super_admin',
            'admin',
            'enterprise_admin',
            'admin_branch',
            'manager',
            'cashier',
            'kitchen',
            'waiter',
            'customer',
        ], $roles->keys()->all());

        $this->assertSame('*', DefaultRole::Admin->getPermissions());

        // The tenant owner is a wildcard scoped to the restaurant, not a hand
        // maintained subset. Pinning it to the branch-admin list silently removed
        // whole modules from every provisioned restaurant.
        $this->assertSame(DefaultRole::TENANT_WILDCARD, DefaultRole::EnterpriseAdmin->getPermissions());
        $this->assertSame([], DefaultRole::Customer->getPermissions());

        $registry = [
            'admin.branches.index',
            'admin.media.index',
            'admin.products.index',
            'admin.saas.index',
            'admin.tenants.index',
            'admin.subscription_plans.index',
            'admin.tenant_subscriptions.index',
            'admin.system_configurations.edit',
        ];

        $this->assertSame($registry, DefaultRole::expandPermissions('*', $registry));
        $this->assertSame(
            ['admin.branches.index', 'admin.media.index', 'admin.products.index'],
            DefaultRole::expandPermissions(DefaultRole::TENANT_WILDCARD, $registry),
            'The tenant wildcard must keep restaurant modules and drop the control plane.',
        );

        $restaurantRoles = [
            DefaultRole::AdminBranch,
            DefaultRole::Manager,
            DefaultRole::Cashier,
            DefaultRole::Kitchen,
            DefaultRole::Waiter,
        ];

        foreach ($restaurantRoles as $role) {
            $permissions = $role->getPermissions();

            $this->assertIsArray($permissions, "{$role->value} must use an explicit permission list.");
            $this->assertNotEmpty($permissions, "{$role->value} must have an operational permission profile.");
            $this->assertSame(
                [],
                array_values(array_filter(
                    $permissions,
                    static fn (string $permission): bool => str_starts_with($permission, 'admin.saas.'),
                )),
                "{$role->value} must never inherit SaaS control-plane permissions.",
            );
        }
    }

    public function test_branch_assignable_roles_exclude_platform_and_customer_profiles(): void
    {
        $this->assertSame([
            'enterprise_admin',
            'admin_branch',
            'manager',
            'waiter',
            'cashier',
            'kitchen',
        ], DefaultRole::getBranchAvailableRoles());

        $this->assertNotContains(DefaultRole::SuperAdmin->value, DefaultRole::getBranchAvailableRoles());
        $this->assertNotContains(DefaultRole::Admin->value, DefaultRole::getBranchAvailableRoles());
        $this->assertNotContains(DefaultRole::Customer->value, DefaultRole::getBranchAvailableRoles());
    }

    public function test_financial_permissions_follow_operational_responsibility(): void
    {
        $administrator = DefaultRole::AdminBranch->getPermissions();
        $manager = DefaultRole::Manager->getPermissions();
        $cashier = DefaultRole::Cashier->getPermissions();
        $kitchen = DefaultRole::Kitchen->getPermissions();
        $waiter = DefaultRole::Waiter->getPermissions();

        foreach ([
            'admin.financial_dashboard.index',
            'admin.financial_dashboard.export',
            'admin.reports.gst_dashboard',
            'admin.reports.gst_invoice_register',
            'admin.reports.gst_credit_notes',
            'admin.reports.finance_reconciliation',
        ] as $permission) {
            $this->assertContains($permission, $administrator);
        }

        $this->assertContains('admin.reports.finance_reconciliation', $manager);
        $this->assertContains('admin.orders.refund', $manager);
        $this->assertContains('admin.orders.refund', $cashier);

        foreach ([$kitchen, $waiter] as $restrictedPermissions) {
            $this->assertNotContains('admin.financial_dashboard.export', $restrictedPermissions);
            $this->assertNotContains('admin.reports.finance_reconciliation', $restrictedPermissions);
            $this->assertNotContains('admin.reports.gst_dashboard', $restrictedPermissions);
        }
    }

    public function test_whatsapp_ordering_permissions_follow_operational_responsibility(): void
    {
        $administrator = DefaultRole::AdminBranch->getPermissions();
        $manager = DefaultRole::Manager->getPermissions();

        foreach ([
            'admin.whatsapp_center.index',
            'admin.whatsapp_ordering.index',
            'admin.whatsapp_ordering.show',
            'admin.whatsapp_ordering.accept',
            'admin.whatsapp_ordering.receive_payment',
        ] as $permission) {
            $this->assertContains($permission, $administrator);
            $this->assertContains($permission, $manager);
        }

        $this->assertContains('admin.whatsapp_ordering.edit', $administrator);
        $this->assertNotContains('admin.whatsapp_ordering.edit', $manager);
    }

    public function test_waiter_role_can_receive_order_payment_for_pay_and_fire(): void
    {
        $this->assertContains(
            'admin.orders.receive_payment',
            DefaultRole::Waiter->getPermissions(),
        );
    }

    public function test_waiter_role_has_shift_cash_and_clearance_permissions(): void
    {
        $permissions = DefaultRole::Waiter->getPermissions();

        $this->assertContains('admin.pos_sessions.index', $permissions);
        $this->assertContains('admin.pos_cash_movements.index', $permissions);
        $this->assertContains('admin.pos_cash_movements.create', $permissions);
        $this->assertContains('admin.expenses.index', $permissions);
        $this->assertContains('admin.expenses.create', $permissions);
        $this->assertContains('admin.waiter_settlements.index', $permissions);
        $this->assertContains('admin.waiter_settlements.create', $permissions);
    }
}
