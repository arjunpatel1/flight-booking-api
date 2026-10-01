<?php

namespace Tests\Unit\Saas;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Modules\Saas\Broadcasting\DualChannelBroadcaster;
use Modules\Saas\Support\RealtimeChannelTelemetry;
use Modules\Saas\Support\TenantChannelNaming;
use Tests\TestCase;

/**
 * Phase 2.5 — realtime channel migration.
 *
 * The single most important assertion is the collision test: two different
 * tenants' branch 6 must map to two DIFFERENT v2 channels. If that ever fails,
 * dedicated databases are unsafe.
 *
 * Tenant resolution is stubbed (the mapping logic is what matters, not the DB)
 * so these run without a database, in CI, regardless of the pdo_sqlite gap.
 */
class RealtimeChannelMigrationTest extends TestCase
{
    /**
     * A naming helper with branch→tenant fixed in code, bypassing the DB.
     */
    private function naming(array $branchTenant): TenantChannelNaming
    {
        return new class($branchTenant) extends TenantChannelNaming {
            public function __construct(private array $map)
            {
            }

            public function tenantForBranch(int $branchId): ?int
            {
                return $this->map[$branchId] ?? null;
            }

            public function tenantForUser(int $userId): ?int
            {
                return $this->map["user:{$userId}"] ?? null;
            }

            public function tenantForAgent(string $agentId): ?int
            {
                return $this->map["agent:{$agentId}"] ?? null;
            }
        };
    }

    public function test_two_tenants_same_branch_id_get_distinct_channels(): void
    {
        // Tenant 9 owns branch 6; tenant 4 also has a branch 6.
        $naming = $this->naming([6 => 9]);
        $a = $naming->toV2('pos.orders.branch.6');

        $naming = $this->naming([6 => 4]);
        $b = $naming->toV2('pos.orders.branch.6');

        $this->assertSame('pos.orders.tenant.9.branch.6', $a);
        $this->assertSame('pos.orders.tenant.4.branch.6', $b);
        $this->assertNotSame($a, $b, 'THE collision test — two tenants, same branch id, must differ.');
    }

    public function test_every_channel_kind_maps_to_a_tenant_namespaced_form(): void
    {
        $naming = $this->naming([6 => 9, 'user:5' => 9, 'agent:printer-x' => 9]);

        $this->assertSame('pos.kitchen.tenant.9.branch.6', $naming->toV2('pos.kitchen.branch.6'));
        $this->assertSame('pos.tables.tenant.9.branch.6', $naming->toV2('pos.tables.branch.6'));
        $this->assertSame('tenant.9.branch.6', $naming->toV2('branch.6'));
        $this->assertSame('tenant.9.agent.printer-x', $naming->toV2('agent.printer-x'));
        $this->assertSame('notifications.tenant.9.user.5', $naming->toV2('notifications.user.5'));
    }

    public function test_private_prefix_is_preserved(): void
    {
        $naming = $this->naming([6 => 9]);
        $this->assertSame('private-pos.orders.tenant.9.branch.6', $naming->toV2('private-pos.orders.branch.6'));
    }

    public function test_unresolvable_tenant_yields_no_v2_channel(): void
    {
        // No mapping for branch 6 → cannot namespace → must return null so the
        // caller keeps v1 only and never drops the message.
        $this->assertNull($this->naming([])->toV2('pos.orders.branch.6'));
    }

    public function test_already_v2_channels_are_recognised_and_not_remapped(): void
    {
        $naming = $this->naming([6 => 9]);
        $this->assertTrue($naming->isV2('pos.orders.tenant.9.branch.6'));
        $this->assertTrue($naming->isV2('tenant.9.branch.6'));
        $this->assertFalse($naming->isV2('pos.orders.branch.6'));
    }

    public function test_dual_broadcaster_emits_both_channels(): void
    {
        $naming = $this->naming([6 => 9]);
        $captured = null;

        $inner = new class($captured) implements Broadcaster {
            public function __construct(public &$captured)
            {
            }

            public function auth($request)
            {
                return true;
            }

            public function validAuthenticationResponse($request, $result)
            {
                return $result;
            }

            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->captured = $channels;
            }
        };

        $dual = new DualChannelBroadcaster($inner, $naming, new RealtimeChannelTelemetry());
        $dual->broadcast(['pos.orders.branch.6'], 'OrderCreated', []);

        $this->assertContains('pos.orders.branch.6', $inner->captured, 'v1 channel still emitted (existing clients).');
        $this->assertContains('pos.orders.tenant.9.branch.6', $inner->captured, 'v2 channel also emitted (migrated clients).');
        $this->assertCount(2, $inner->captured);
    }

    public function test_dual_broadcaster_never_drops_an_unresolvable_channel(): void
    {
        $naming = $this->naming([]); // nothing resolves
        $captured = null;
        $inner = new class($captured) implements Broadcaster {
            public function __construct(public &$captured)
            {
            }
            public function auth($request)
            {
                return true;
            }
            public function validAuthenticationResponse($request, $result)
            {
                return $result;
            }
            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->captured = $channels;
            }
        };

        $dual = new DualChannelBroadcaster($inner, $naming, new RealtimeChannelTelemetry());
        $dual->broadcast(['pos.orders.branch.6'], 'OrderCreated', []);

        $this->assertSame(['pos.orders.branch.6'], $inner->captured,
            'Unresolvable → v1 only, message never dropped.');
    }
}
