<?php

namespace Tests\Feature\Pos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Order\Enums\OrderStatus;
use Modules\SeatingPlan\Models\Floor;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\Zone;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * P4 — Order History data completeness. Guards the `floor` field added to
 * OrderResource (resolved via the table.floor relation eager-loaded in
 * OrderService::get) so the order history can show Floor alongside table.
 */
class OrderHistoryFloorTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    public function test_orders_index_includes_floor_name_for_an_order_on_a_table(): void
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
            'name' => 'Family Area',
            'is_active' => true,
        ]);

        $table = Table::factory()->create([
            'branch_id' => $branch->id,
            'floor_id' => $floor->id,
            'zone_id' => $zone->id,
            'name' => 'T12',
        ]);

        $this->makeOrder($branch, [
            'table_id' => $table->id,
            'status' => OrderStatus::Completed,
        ]);

        $user = $this->actingAsUserWithPermissions(['admin.orders.index']);
        $user->forceFill(['branch_id' => $branch->id])->save();
        $user->refresh();

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonFragment(['floor' => 'Ground Floor'])
            ->assertJsonFragment(['table' => 'T12']);
    }
}
