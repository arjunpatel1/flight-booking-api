<?php

namespace Tests\Feature\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Saas\Models\Tenant;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\Role;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * Drives the real cancel-with-manager-approval flow: request a token from
 * /pos/manager-approvals/approve, then spend it on /orders/{id}/cancel.
 */
class CancelWithManagerApprovalTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();

        // OrderUpdateStatus broadcasts; without a Reverb server the driver
        // throws and masks the result under a 500.
        config(['broadcasting.default' => 'null']);
    }

    private function makeManager(int $branchId, string $pin = '4321'): User
    {
        $role = Role::findOrCreate(DefaultRole::Manager->value, 'api');

        $manager = User::factory()->create(['is_active' => true]);
        $manager->forceFill([
            'branch_id' => $branchId,
            'tenant_id' => DB::table('branches')->where('id', $branchId)->value('tenant_id'),
            'pos_pin_hash' => Hash::make($pin),
        ])->save();
        $manager->assignRole($role);

        return $manager->refresh();
    }

    private function makeTenantBranch()
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->create([
            'name' => 'Tenant '.uniqid(),
            'slug' => 'tenant-'.uniqid(),
            'domain' => uniqid().'.example.test',
            'is_active' => true,
        ]);

        return $this->makeBranch(['tenant_id' => $tenant->id]);
    }

    public function test_manager_choices_never_include_another_tenant(): void
    {
        $branch = $this->makeTenantBranch();
        $localManager = $this->makeManager($branch->id);
        $foreignBranch = $this->makeTenantBranch();
        $foreignManager = $this->makeManager($foreignBranch->id);

        $managers = app(\Modules\Pos\Services\ManagerApproval\PosManagerApprovalService::class)
            ->eligibleManagers($branch->id);

        $this->assertTrue($managers->contains('id', $localManager->id));
        $this->assertFalse($managers->contains('id', $foreignManager->id));
    }

    public function test_tenant_actor_cannot_approve_against_another_tenants_branch(): void
    {
        $localBranch = $this->makeTenantBranch();
        $foreignBranch = $this->makeTenantBranch();
        $foreignManager = $this->makeManager($foreignBranch->id);
        $actor = $this->makeManager($localBranch->id);

        $this->actingAs($actor, 'api')
            ->postJson('/api/v1/pos/manager-approvals/approve', [
                'manager_id' => $foreignManager->id,
                'pin' => '4321',
                'branch_id' => $foreignBranch->id,
                'action' => 'order.cancel',
                'resource_type' => 'order',
                'resource_id' => '123',
            ])
            ->assertForbidden();
    }

    public function test_platform_superadmin_capability_is_explicit_self_approval_only(): void
    {
        $branch = $this->makeTenantBranch();
        $platformAdmin = User::factory()->create([
            'tenant_id' => null,
            'branch_id' => null,
            'is_active' => true,
        ]);
        $platformAdmin->forceFill(['pos_pin_hash' => Hash::make('4321')])->save();
        $platformAdmin->assignRole(Role::findOrCreate(DefaultRole::SuperAdmin->value, 'api'));

        $service = app(\Modules\Pos\Services\ManagerApproval\PosManagerApprovalService::class);

        $this->assertTrue($service->actorCanAccessBranch($platformAdmin, $branch->id));
        $this->assertTrue($service->canApproveAtBranch($platformAdmin->refresh(), $branch->id, $platformAdmin));
        $this->assertFalse($service->eligibleManagers($branch->id)->contains('id', $platformAdmin->id));
    }

    private function makeReason(): int
    {
        return DB::table('reasons')->insertGetId([
            'name' => json_encode(['en' => 'Customer changed mind']),
            'type' => 'cancellation',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function entitlePos(int $tenantId): void
    {
        $planId = DB::table('subscription_plans')->insertGetId([
            'name' => 'POS Test Plan '.uniqid(),
            'code' => 'pos-test-'.uniqid(),
            'features' => json_encode(['pos', 'pos_registers'], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_subscriptions')->insert([
            'tenant_id' => $tenantId,
            'subscription_plan_id' => $planId,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeRegister(int $branchId): int
    {
        return DB::table('pos_registers')->insertGetId([
            'branch_id' => $branchId,
            'name' => json_encode(['en' => 'Counter 1']),
            'note' => json_encode(['en' => 'Test register']),
            'code' => 'REG-'.uniqid(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Cancel/refund abort with 400 unless the register has an OPEN session. */
    private function openSession(int $registerId, int $branchId, int $userId): void
    {
        DB::table('pos_sessions')->insert([
            'pos_register_id' => $registerId,
            'branch_id' => $branchId,
            'opened_by' => $userId,
            'opened_at' => now(),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_an_order_can_be_cancelled_with_a_valid_manager_approval(): void
    {
        $branch = $this->makeTenantBranch();
        $this->entitlePos((int) $branch->tenant_id);
        $manager = $this->makeManager($branch->id);
        $order = $this->makeOrder($branch, [
            'status' => OrderStatus::Pending,
            'payment_status' => OrderPaymentStatus::Unpaid,
        ]);

        $actor = $this->actingAsUserWithPermissions([
            'admin.pos.index',
            'admin.orders.cancel',
        ]);
        $actor->forceFill([
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
        ])->save();
        $actor->refresh();

        $registerId = $this->makeRegister($branch->id);
        $this->openSession($registerId, $branch->id, $actor->id);

        $token = $this->postJson('/api/v1/pos/manager-approvals/approve', [
            'manager_id' => $manager->id,
            'pin' => '4321',
            'branch_id' => $branch->id,
            'action' => 'order.cancel',
            'resource_type' => 'order',
            'resource_id' => (string) $order->id,
        ])->assertOk()->json('data.approval_token');

        $this->assertNotEmpty($token, 'Approval must return a token.');

        $this->postJson(
            "/api/v1/orders/{$order->id}/cancel",
            [
                'reason_id' => $this->makeReason(),
                'register_id' => $registerId,
                'manager_approval_token' => $token,
            ],
            ['Idempotency-Key' => 'cancel-'.uniqid()]
        )->assertOk();

        $this->assertSame(
            OrderStatus::Cancelled->value,
            $order->fresh()->status->value,
            'The order must actually reach Cancelled.'
        );
    }

    /**
     * Regression: the register dropdown was fed by every active register, so a
     * user could pick one with no open session. Cancel then aborted 400 after
     * the manager had already approved, which looked like the approval failing.
     */
    public function test_update_status_meta_only_offers_registers_with_an_open_session(): void
    {
        $branch = $this->makeTenantBranch();
        $order = $this->makeOrder($branch, [
            'status' => OrderStatus::Pending,
            'payment_status' => OrderPaymentStatus::Unpaid,
        ]);

        $actor = $this->actingAsUserWithPermissions(['admin.pos.index', 'admin.orders.cancel']);
        $actor->forceFill(['branch_id' => $branch->id])->save();
        $actor->refresh();

        $usable = $this->makeRegister($branch->id);
        $this->openSession($usable, $branch->id, $actor->id);
        $unusable = $this->makeRegister($branch->id);

        $registers = app(\Modules\Order\Services\Order\OrderServiceInterface::class)
            ->getUpdateStatusMeta($order->id)['pos_registers'];

        $ids = collect($registers)->pluck('id')->all();

        $this->assertContains($usable, $ids, 'A register with an open session must be offered.');
        $this->assertNotContains($unusable, $ids, 'A register with no open session cannot complete the action.');
    }

    public function test_cancelling_without_a_token_is_rejected(): void
    {
        $branch = $this->makeTenantBranch();
        $this->makeManager($branch->id);
        $order = $this->makeOrder($branch, [
            'status' => OrderStatus::Pending,
            'payment_status' => OrderPaymentStatus::Unpaid,
        ]);

        $actor = $this->actingAsUserWithPermissions(['admin.pos.index', 'admin.orders.cancel']);
        $actor->forceFill(['branch_id' => $branch->id])->save();
        $actor->refresh();

        $registerId = $this->makeRegister($branch->id);
        $this->openSession($registerId, $branch->id, $actor->id);

        // The session check runs first, so give it a usable register: this must
        // fail on the missing approval token, not on session state.
        $this->postJson(
            "/api/v1/orders/{$order->id}/cancel",
            [
                'reason_id' => $this->makeReason(),
                'register_id' => $registerId,
            ],
            ['Idempotency-Key' => 'cancel-'.uniqid()]
        )->assertStatus(422);

        $this->assertSame(OrderStatus::Pending->value, $order->fresh()->status->value);
    }
}
