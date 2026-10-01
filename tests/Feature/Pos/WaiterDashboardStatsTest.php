<?php

namespace Tests\Feature\Pos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Support\KitchenSla;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Floor;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\Zone;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\Role;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * P3 — Order Summary Dashboard. Guards the cancelled_orders stat added to
 * PosViewerService::waiterDashboard (counts Cancelled + Refunded), alongside the
 * existing total/completed counters.
 *
 * Not gated with #[RequiresPhpExtension('pdo_sqlite')] so it runs against the
 * configured DB connection (MySQL scratch DB locally).
 */
class WaiterDashboardStatsTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    public function test_waiter_dashboard_reports_cancelled_orders_count(): void
    {
        $branch = $this->makeBranch();

        // 2 cancelled + 1 refunded should both count toward cancelled_orders (3).
        $this->makeOrder($branch, ['status' => OrderStatus::Cancelled]);
        $this->makeOrder($branch, ['status' => OrderStatus::Cancelled]);
        $this->makeOrder($branch, ['status' => OrderStatus::Refunded]);
        // Non-cancelled orders must NOT be counted as cancelled.
        $this->makeOrder($branch, ['status' => OrderStatus::Completed]);
        $this->makeOrder($branch, ['status' => OrderStatus::Pending]);

        $user = $this->actingAsUserWithPermissions(['admin.pos.index']);
        // The dashboard scopes to the user's assigned branch — make sure the
        // attribute is loaded (strict attribute access) and matches the orders.
        $user->forceFill(['branch_id' => $branch->id])->save();
        $user->refresh();

        $response = $this->getJson('/api/v1/pos/waiter-dashboard?branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonStructure([
                'body' => [
                    'stats' => [
                        'total_orders',
                        'active_orders',
                        'completed_orders',
                        'cancelled_orders',
                        'total_revenue',
                        'average_order_value',
                    ],
                ],
            ]);

        $stats = $response->json('body.stats');
        $this->assertSame(5, $stats['total_orders']);
        $this->assertSame(3, $stats['cancelled_orders']);
        $this->assertSame(1, $stats['completed_orders']);
        $this->assertSame(1, $stats['active_orders']);
    }

    public function test_waiter_dashboard_active_and_recent_orders_match_order_management_definitions(): void
    {
        $branch = $this->makeBranch();
        $pending = $this->makeOrder($branch, [
            'status' => OrderStatus::Pending,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'total' => 100,
        ]);
        $servedUnpaid = $this->makeOrder($branch, [
            'status' => OrderStatus::Served,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'total' => 200,
        ]);
        $this->makeOrder($branch, [
            'status' => OrderStatus::Ready,
            'payment_status' => OrderPaymentStatus::Paid,
            'total' => 300,
        ]);
        $paidCompleted = $this->makeOrder($branch, [
            'status' => OrderStatus::Completed,
            'payment_status' => OrderPaymentStatus::Paid,
            'total' => 400,
        ]);
        $unpaidCompleted = $this->makeOrder($branch, [
            'status' => OrderStatus::Completed,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'total' => 500,
        ]);
        $this->makeOrder($branch, [
            'status' => OrderStatus::Cancelled,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'total' => 600,
        ]);

        $user = $this->actingAsUserWithPermissions(['admin.pos.index']);
        $user->forceFill(['branch_id' => $branch->id])->save();
        $user->refresh();

        $response = $this->getJson('/api/v1/pos/waiter-dashboard?branch_id='.$branch->id)
            ->assertOk();

        $this->assertSame(2, $response->json('body.stats.active_orders'));
        $this->assertSame(2, $response->json('body.stats.completed_orders'));
        $this->assertEquals(400, $response->json('body.stats.total_revenue.amount'));

        $recentIds = collect($response->json('body.orders'))
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();

        $this->assertContains($paidCompleted->id, $recentIds);
        $this->assertContains($unpaidCompleted->id, $recentIds);
        $this->assertNotContains($pending->id, $recentIds);
        $this->assertNotContains($servedUnpaid->id, $recentIds);
    }

    public function test_waiter_assistant_returns_operational_cards_with_standard_action_policy(): void
    {
        $branch = $this->makeBranch();
        $readyOrder = $this->makeOrder($branch, [
            'status' => OrderStatus::Ready,
        ]);
        $readyOrder->forceFill([
            'created_at' => now()->subMinutes(8),
            'updated_at' => now()->subMinutes(1),
        ])->save();

        $user = $this->actingAsUserWithPermissions(['admin.pos.index']);
        $user->forceFill(['branch_id' => $branch->id])->save();
        $user->refresh();

        $response = $this->getJson('/api/v1/pos/waiter-assistant?branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonStructure([
                'body' => [
                    'branch_id',
                    'generated_at',
                    'restaurant_health' => [
                        'score',
                        'status',
                        'label',
                        'critical_count',
                        'warning_count',
                        'drivers',
                    ],
                    'next_action' => [
                        'id',
                        'is_next_action',
                        'action_policy',
                        'business_impact' => [
                            'impact_type',
                            'estimated_loss',
                            'time_lost_minutes',
                            'priority_score',
                            'recommended_action',
                        ],
                    ],
                    'cards' => [
                        '*' => [
                            'id',
                            'dismiss_token',
                            'type',
                            'priority',
                            'priority_score',
                            'severity',
                            'title',
                            'message',
                            'reason',
                            'business_impact' => [
                                'business',
                                'customer',
                                'revenue',
                                'impact_type',
                                'estimated_loss',
                                'time_lost_minutes',
                                'priority_score',
                                'recommended_action',
                            ],
                            'current_status',
                            'entity_type',
                            'entity_id',
                            'table_id',
                            'order_id',
                            'action',
                            'action_label',
                            'action_chain' => [
                                '*' => [
                                    'key',
                                    'label',
                                    'action',
                                    'position',
                                    'state',
                                ],
                            ],
                            'expected_time_seconds',
                            'expected_time_label',
                            'ignored_minutes',
                            'escalation' => [
                                'ignored_minutes',
                                'level',
                                'manager_notification_due',
                                'critical_after_minutes',
                                'manager_notify_after_minutes',
                            ],
                            'action_policy' => [
                                'allowed',
                                'visible',
                                'reason',
                                'requires_manager_approval',
                                'loading_key',
                                'permissions',
                                'confirmation_required',
                            ],
                            'created_at',
                            'expires_at',
                        ],
                    ],
                ],
            ]);

        $cards = $response->json('body.cards');
        $this->assertNotEmpty($cards);
        $this->assertContains('kitchen_ready', collect($cards)->pluck('type')->all());

        $ready = collect($cards)->firstWhere('type', 'kitchen_ready');
        $this->assertSame('service_delay', data_get($ready, 'business_impact.impact_type'));
        $this->assertSame($ready['priority_score'], data_get($ready, 'business_impact.priority_score'));
        $this->assertNotEmpty(data_get($ready, 'business_impact.recommended_action'));
        $this->assertIsArray($ready['action_policy']);

        $nextAction = $response->json('body.next_action');
        $this->assertNotEmpty($nextAction['id']);
        $this->assertTrue($response->json('body.next_action.is_next_action'));
        $this->assertIsArray($response->json('body.next_action.action_policy'));
        $this->assertNotEmpty($response->json('body.next_action.business_impact.recommended_action'));

        $nextCard = collect($cards)->firstWhere('id', $nextAction['id']);
        $this->assertNotNull($nextCard);
        $this->assertTrue((bool) data_get($nextCard, 'is_next_action'));
    }

    public function test_waiter_assistant_scopes_waiter_cards_to_own_orders(): void
    {
        $branch = $this->makeBranch();
        Role::findOrCreate(DefaultRole::Waiter->value, 'api');
        $waiter = $this->actingAsUserWithPermissions(['admin.pos.index', 'admin.orders.active']);
        $waiter->syncRoles([DefaultRole::Waiter->value]);
        $waiter->forceFill(['branch_id' => $branch->id])->save();
        $waiter->refresh();
        $otherWaiter = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $otherWaiter->syncRoles([DefaultRole::Waiter->value]);

        $ownOrder = $this->makeOrder($branch, [
            'waiter_id' => $waiter->id,
            'status' => OrderStatus::Ready,
        ]);
        $ownOrder->forceFill(['updated_at' => now()->subMinute()])->save();
        $otherOrder = $this->makeOrder($branch, [
            'waiter_id' => $otherWaiter->id,
            'status' => OrderStatus::Ready,
        ]);
        $otherOrder->forceFill(['updated_at' => now()->subMinute()])->save();

        $cards = $this->getJson('/api/v1/pos/waiter-assistant?branch_id='.$branch->id)
            ->assertOk()
            ->json('body.cards');

        $orderIds = collect($cards)
            ->where('type', 'kitchen_ready')
            ->pluck('order_id')
            ->map(fn($id) => (int) $id)
            ->all();

        $this->assertContains($ownOrder->id, $orderIds);
        $this->assertNotContains($otherOrder->id, $orderIds);
    }

    public function test_waiter_assistant_uses_canonical_kitchen_sla_for_delayed_orders(): void
    {
        setting([
            'kitchen_delayed_order_minutes' => 15,
            'pos_delayed_order_alert_minutes' => 45,
        ]);
        app(SettingServiceInterface::class)->refreshSettingBinding();

        $branch = $this->makeBranch();
        $order = $this->makeOrder($branch, [
            'status' => OrderStatus::Preparing,
        ]);
        $order->forceFill([
            'created_at' => now()->subMinutes(35),
            'updated_at' => now()->subMinutes(2),
        ])->save();

        $sla = app(KitchenSla::class)->payload($order->fresh());
        $this->assertSame(15, $sla['threshold_minutes']);
        $this->assertTrue($sla['is_delayed']);
        $this->assertSame('critical', $sla['severity']);
        $this->assertSame('manager', $sla['escalation_level']);

        $user = $this->actingAsUserWithPermissions(['admin.pos.index', 'admin.orders.active']);
        $user->forceFill(['branch_id' => $branch->id])->save();
        $user->refresh();

        $cards = $this->getJson('/api/v1/pos/waiter-assistant?branch_id='.$branch->id)
            ->assertOk()
            ->json('body.cards');

        $delayed = collect($cards)->firstWhere('type', 'order_delayed');
        $this->assertNotNull($delayed);
        $this->assertSame($order->id, $delayed['order_id']);
        $this->assertSame('critical', $delayed['severity']);
    }

    public function test_performance_intelligence_returns_explainable_decision_payload(): void
    {
        $branch = $this->makeBranch();
        $completed = $this->makeOrder($branch, [
            'status' => OrderStatus::Completed,
        ]);
        $completed->forceFill([
            'created_at' => now()->subMinutes(42),
            'closed_at' => now()->subMinutes(4),
            'payment_at' => now()->subMinutes(6),
        ])->save();

        $ready = $this->makeOrder($branch, [
            'status' => OrderStatus::Ready,
        ]);
        $ready->forceFill([
            'created_at' => now()->subMinutes(18),
            'updated_at' => now()->subMinutes(1),
        ])->save();

        $user = $this->actingAsUserWithPermissions([
            'admin.pos.index',
            'admin.orders.active',
            'admin.pos.kitchen_viewer',
            'admin.reports.index',
        ]);
        $user->forceFill(['branch_id' => $branch->id])->save();
        $user->refresh();

        $response = $this->getJson('/api/v1/pos/performance-intelligence?branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonStructure([
                'body' => [
                    'branch_id',
                    'generated_at',
                    'period' => ['key', 'label', 'from', 'to', 'comparison_from', 'comparison_to'],
                    'implementation_audit',
                    'scores' => [
                        'overall' => ['score', 'status', 'explanation'],
                        'waiter' => ['score', 'status', 'explanation'],
                        'kitchen' => ['score', 'status', 'explanation'],
                        'table' => ['score', 'status', 'explanation'],
                        'printer' => ['score', 'status', 'explanation'],
                        'realtime' => ['score', 'status', 'explanation'],
                        'revenue' => ['score', 'status', 'explanation'],
                        'guest_experience' => ['score', 'status', 'explanation'],
                    ],
                    'restaurant_health' => ['score', 'status', 'label', 'drivers'],
                    'waiter_performance' => ['summary', 'restaurant_average', 'comparison', 'rows', 'source'],
                    'kitchen_performance' => ['summary', 'rows', 'source'],
                    'table_performance' => ['summary', 'rows', 'source'],
                    'printer_performance' => ['summary', 'source'],
                    'terminal_performance' => ['summary', 'source'],
                    'revenue_performance' => ['summary', 'source'],
                    'bottlenecks',
                    'recommendations',
                    'operation_timeline',
                    'accuracy' => ['source', 'mode', 'confidence', 'warnings'],
                ],
            ]);

        $this->assertSame('deterministic', $response->json('body.accuracy.mode'));
        $this->assertIsNumeric($response->json('body.scores.overall.score'));
        $this->assertGreaterThanOrEqual(2, $response->json('body.waiter_performance.summary.orders_handled'));
    }

    public function test_revenue_intelligence_returns_deterministic_leak_and_growth_payload(): void
    {
        $branch = $this->makeBranch();
        $completed = $this->makeOrder($branch, [
            'status' => OrderStatus::Completed,
            'payment_status' => OrderPaymentStatus::Paid,
            'total' => 500,
            'subtotal' => 500,
            'guest_count' => 2,
        ]);
        $completed->forceFill(['created_at' => now()->subMinutes(40), 'closed_at' => now()->subMinutes(5)])->save();

        DB::table('order_discounts')->insert([
            'order_id' => $completed->id,
            'discountable_id' => 1,
            'discountable_type' => 'manual',
            'type' => 'discount',
            'currency' => 'INR',
            'currency_rate' => 1,
            'amount' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pending = $this->makeOrder($branch, [
            'status' => OrderStatus::Served,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'total' => 300,
            'subtotal' => 300,
        ]);
        $pending->forceFill(['created_at' => now()->subMinutes(45)])->save();

        $user = $this->actingAsUserWithPermissions(['admin.pos.index', 'admin.reports.index']);
        $user->forceFill(['branch_id' => $branch->id])->save();
        $user->refresh();

        $response = $this->getJson('/api/v1/pos/revenue-intelligence?branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonStructure([
                'body' => [
                    'branch_id',
                    'generated_at',
                    'period' => ['key', 'label', 'from', 'to', 'comparison_from', 'comparison_to'],
                    'summary' => ['orders', 'revenue', 'gross_profit', 'average_order_value', 'correction_rate_percent', 'payment_pending_count'],
                    'revenue_leaks' => [
                        '*' => ['id', 'type', 'severity', 'title', 'estimated_revenue_loss', 'estimated_profit_loss', 'reason', 'recommended_action', 'confidence', 'evidence', 'action_policy'],
                    ],
                    'upsell_engine' => ['summary', 'pairings'],
                    'order_value_engine' => ['rows', 'source'],
                    'smart_menu_engine' => ['high_sellers', 'hidden_stars', 'dead_products', 'source'],
                    'waiter_revenue_score' => ['rows', 'source'],
                    'loss_prevention',
                    'business_coach',
                    'benchmarks',
                    'accuracy' => ['mode', 'source', 'confidence', 'warnings'],
                ],
            ]);

        $this->assertSame('deterministic', $response->json('body.accuracy.mode'));
        $this->assertGreaterThanOrEqual(2, $response->json('body.summary.orders'));
        $this->assertContains('discount_abuse', collect($response->json('body.revenue_leaks'))->pluck('type')->all());
    }

    public function test_owner_command_center_composes_live_operations_with_policy_backed_actions(): void
    {
        $branch = $this->makeBranch();
        $floor = Floor::factory()->create([
            'branch_id' => $branch->id,
            'name' => 'Ground Floor',
            'is_active' => true,
        ]);
        $zone = Zone::factory()->create([
            'branch_id' => $branch->id,
            'floor_id' => $floor->id,
            'name' => 'Main Zone',
            'is_active' => true,
        ]);
        $table = Table::factory()->create([
            'branch_id' => $branch->id,
            'floor_id' => $floor->id,
            'zone_id' => $zone->id,
            'name' => 'T12',
            'status' => TableStatus::Occupied,
        ]);
        $this->makeOrder($branch, [
            'table_id' => $table->id,
            'status' => OrderStatus::Ready,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'total' => 725,
            'subtotal' => 725,
            'created_at' => now()->subMinutes(35),
            'updated_at' => now()->subMinutes(12),
        ]);

        $user = $this->actingAsUserWithPermissions([
            'admin.pos.index',
            'admin.tables.viewer',
            'admin.orders.index',
            'admin.orders.active',
            'admin.orders.receive_payment',
            'admin.pos.kitchen_viewer',
        ]);
        $user->forceFill(['branch_id' => $branch->id])->save();
        $user->refresh();

        $response = $this->getJson('/api/v1/pos/owner-command-center?branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonStructure([
                'body' => [
                    'branch_id',
                    'generated_at',
                    'health' => ['score', 'level', 'critical_count', 'warning_count'],
                    'live' => [
                        'revenue_today',
                        'orders_running',
                        'kitchen_load',
                        'waiter_load',
                        'printer_health',
                        'realtime_status',
                        'offline_queue',
                        'payment_queue',
                        'reservation_queue',
                        'automation_queue',
                    ],
                    'heatmap' => [
                        '*' => ['table_id', 'name', 'status', 'running_orders', 'revenue', 'waiting_minutes', 'heat', 'heat_reason', 'action_policy'],
                    ],
                    'executive_summary' => ['problems', 'opportunities', 'risks', 'recommendations'],
                    'actions' => [
                        '*' => ['id', 'title', 'action', 'action_policy'],
                    ],
                    'timeline',
                    'performance_cards',
                    'business_coach',
                    'sources',
                ],
            ]);

        $this->assertSame($branch->id, $response->json('body.branch_id'));
        $this->assertGreaterThanOrEqual(1, $response->json('body.live.orders_running'));
        $this->assertContains($table->id, collect($response->json('body.heatmap'))->pluck('table_id')->all());
        $this->assertNotEmpty($response->json('body.actions'));
        $this->assertTrue(collect($response->json('body.actions'))->every(fn($action) => array_key_exists('allowed', $action['action_policy'])));
    }

    public function test_restaurant_automation_returns_policy_backed_operational_decisions(): void
    {
        $branch = $this->makeBranch();
        $floor = Floor::factory()->create([
            'branch_id' => $branch->id,
            'name' => 'Ground Floor',
            'is_active' => true,
        ]);
        $zone = Zone::factory()->create([
            'branch_id' => $branch->id,
            'floor_id' => $floor->id,
            'name' => 'Main Zone',
            'is_active' => true,
        ]);
        $table = Table::factory()->create([
            'branch_id' => $branch->id,
            'floor_id' => $floor->id,
            'zone_id' => $zone->id,
            'name' => 'T8',
            'status' => TableStatus::Occupied,
            'updated_at' => now()->subMinutes(20),
        ]);
        $order = $this->makeOrder($branch, [
            'table_id' => $table->id,
            'status' => OrderStatus::Completed,
            'payment_status' => OrderPaymentStatus::Paid,
            'total' => 450,
        ]);
        $order->forceFill(['updated_at' => now()->subMinutes(15), 'closed_at' => now()->subMinutes(12)])->save();

        $user = $this->actingAsUserWithPermissions([
            'admin.pos.index',
            'admin.tables.update_status',
            'admin.tables.viewer',
            'admin.orders.active',
        ]);
        $user->forceFill(['branch_id' => $branch->id])->save();
        $user->refresh();

        $response = $this->getJson('/api/v1/pos/automation?branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonStructure([
                'body' => [
                    'branch_id',
                    'generated_at',
                    'framework' => ['version', 'mode', 'policy_source', 'execution_policy', 'audit_stream'],
                    'summary' => ['total', 'active', 'auto_eligible', 'blocked', 'critical'],
                    'readiness_report',
                    'playbooks',
                    'analytics',
                    'decisions' => [
                        '*' => [
                            'id',
                            'domain',
                            'trigger',
                            'title',
                            'conditions',
                            'safety_rules',
                            'permissions',
                            'action',
                            'rollback',
                            'notification',
                            'state',
                            'executable',
                            'action_policy' => ['allowed', 'visible', 'loading_key', 'permissions'],
                            'audit',
                        ],
                    ],
                ],
            ]);

        $tableDecision = collect($response->json('body.decisions'))
            ->firstWhere('id', 'table-paid-idle');

        $this->assertNotNull($tableDecision);
        $this->assertSame('suggested', $tableDecision['state']);
        $this->assertTrue($tableDecision['action_policy']['allowed']);
        $this->assertSame($table->id, $tableDecision['evidence']['table_id']);
    }

    /**
     * Waiter performance timing. kot_finalized_at is not populated by the
     * current order flow, so a kitchen-vs-service split is not derivable;
     * these measure order-placed -> served and order-placed -> settled.
     */
    public function test_waiter_dashboard_reports_fulfilment_timing(): void
    {
        $branch = $this->makeBranch();

        // Served after 20 minutes, settled after 50.
        $first = $this->makeOrder($branch, ['status' => OrderStatus::Served]);
        DB::table('orders')->where('id', $first->id)->update([
            'created_at' => now()->subMinutes(60),
            'served_at' => now()->subMinutes(40),
            'payment_at' => now()->subMinutes(10),
        ]);

        // Served after 30 minutes, settled after 70 (closed_at wins).
        $second = $this->makeOrder($branch, ['status' => OrderStatus::Completed]);
        DB::table('orders')->where('id', $second->id)->update([
            'created_at' => now()->subMinutes(90),
            'served_at' => now()->subMinutes(60),
            'closed_at' => now()->subMinutes(20),
        ]);

        // Still in progress: must not drag the averages toward zero.
        $this->makeOrder($branch, ['status' => OrderStatus::Preparing]);

        $user = $this->actingAsUserWithPermissions(['admin.pos.index']);
        $user->forceFill(['branch_id' => $branch->id])->save();
        $user->refresh();

        $stats = $this->getJson('/api/v1/pos/waiter-dashboard?branch_id='.$branch->id)
            ->assertOk()
            ->json('body.stats');

        $this->assertSame(2, $stats['served_orders']);
        // (20 + 30) / 2 minutes
        $this->assertSame(25 * 60, $stats['avg_serve_seconds']);
        // (50 + 70) / 2 minutes
        $this->assertSame(60 * 60, $stats['avg_settle_seconds']);
    }

    public function test_fulfilment_timing_is_null_when_nothing_reached_the_milestone(): void
    {
        $branch = $this->makeBranch();
        $this->makeOrder($branch, ['status' => OrderStatus::Preparing]);

        $user = $this->actingAsUserWithPermissions(['admin.pos.index']);
        $user->forceFill(['branch_id' => $branch->id])->save();
        $user->refresh();

        $stats = $this->getJson('/api/v1/pos/waiter-dashboard?branch_id='.$branch->id)
            ->assertOk()
            ->json('body.stats');

        $this->assertSame(0, $stats['served_orders']);
        $this->assertNull($stats['avg_serve_seconds'], 'A dash is better than a fake zero.');
        $this->assertNull($stats['avg_settle_seconds']);
    }
}
