<?php

namespace Tests\Feature\Pos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Pos\Models\KitchenStation;
use Modules\Pos\Models\KitchenStationOrderProduct;
use Modules\Product\Models\Product;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * KDS depth — recall (un-bump). A completed/bumped kitchen item can be pulled back
 * to the preparing queue (premature bump / re-fire), which Petpooja/Toast both
 * support. Served items cannot be recalled.
 *
 * Ungated (no #[RequiresPhpExtension('pdo_sqlite')]) so it runs on the MySQL scratch DB.
 */
class KitchenRecallApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
        // KDS transitions broadcast order/kitchen updates; keep them off the wire.
        config(['broadcasting.default' => 'null']);
        // Factory products have no media; relax strict-mode so serialising the
        // returned item (product appends *_url) doesn't trip on the missing image.
        \Illuminate\Database\Eloquent\Model::preventAccessingMissingAttributes(false);
    }

    protected function tearDown(): void
    {
        \Illuminate\Database\Eloquent\Model::preventAccessingMissingAttributes(true);
        parent::tearDown();
    }

    /** @return array{0: KitchenStation, 1: KitchenStationOrderProduct, 2: \Modules\Order\Models\OrderProduct} */
    private function completedItem(\Modules\Branch\Models\Branch $branch, string $itemStatus = 'completed', OrderProductStatus $opStatus = OrderProductStatus::Ready): array
    {
        $station = KitchenStation::query()->create([
            'name' => 'Grill',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);

        $order = $this->makeOrder($branch);
        $product = Product::factory()->create(['menu_id' => $this->makeMenu($branch)->id]);
        $op = $order->products()->create([
            'product_id' => $product->id,
            'currency' => 'INR',
            'currency_rate' => 1,
            'unit_price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
            'tax_total' => 0,
            'total' => 100,
            'cost_price' => 0,
            'revenue' => 100,
            'status' => $opStatus,
        ]);

        $item = KitchenStationOrderProduct::query()->create([
            'kitchen_station_id' => $station->id,
            'order_product_id' => $op->id,
            'status' => $itemStatus,
            'prep_started_at' => now()->subMinutes(5),
            'prep_completed_at' => $itemStatus === 'completed' ? now() : null,
            'bumped_at' => $itemStatus === 'completed' ? now() : null,
        ]);

        return [$station, $item, $op];
    }

    private function actAsKitchenUserFor(\Modules\Branch\Models\Branch $branch): void
    {
        $user = $this->actingAsUserWithPermissions(['admin.pos.kitchen_stations']);
        $user->forceFill(['branch_id' => $branch->id])->save();
        $user->refresh();
    }

    public function test_recall_returns_completed_item_to_preparing(): void
    {
        $branch = $this->makeBranch();
        [$station, $item, $op] = $this->completedItem($branch);
        $this->actAsKitchenUserFor($branch);

        $this->postJson("/api/v1/pos/kitchen-stations/{$station->id}/items/{$item->id}/recall")
            ->assertOk();

        $item->refresh();
        $op->refresh();

        $this->assertSame('preparing', $item->status);
        $this->assertNull($item->prep_completed_at);
        $this->assertNull($item->bumped_at);
        $this->assertSame(OrderProductStatus::Preparing, $op->status);
    }

    public function test_recall_rejects_item_not_completed(): void
    {
        $branch = $this->makeBranch();
        [$station, $item] = $this->completedItem($branch, itemStatus: 'preparing', opStatus: OrderProductStatus::Preparing);
        $this->actAsKitchenUserFor($branch);

        $this->postJson("/api/v1/pos/kitchen-stations/{$station->id}/items/{$item->id}/recall")
            ->assertStatus(422);
    }

    public function test_recall_rejects_served_item(): void
    {
        $branch = $this->makeBranch();
        [$station, $item] = $this->completedItem($branch, opStatus: OrderProductStatus::Served);
        $this->actAsKitchenUserFor($branch);

        $this->postJson("/api/v1/pos/kitchen-stations/{$station->id}/items/{$item->id}/recall")
            ->assertStatus(422);
    }

    // ── Hardening: bad / hard cases ─────────────────────────────────────────

    public function test_recall_unknown_item_returns_404(): void
    {
        $branch = $this->makeBranch();
        [$station] = $this->completedItem($branch);
        $this->actAsKitchenUserFor($branch);

        $this->postJson("/api/v1/pos/kitchen-stations/{$station->id}/items/999999999/recall")
            ->assertNotFound();
    }

    public function test_recall_is_blocked_across_branches(): void
    {
        // Station/item live in branch A; a branch-B kitchen user must not recall it.
        // KitchenStation carries the HasBranch global scope, so the station is
        // invisible to the other branch — the request 404s (it doesn't even reveal
        // the station exists), and the item is left untouched.
        $branchA = $this->makeBranch();
        [$station, $item] = $this->completedItem($branchA);

        $branchB = $this->makeBranch();
        $this->actAsKitchenUserFor($branchB);

        $this->postJson("/api/v1/pos/kitchen-stations/{$station->id}/items/{$item->id}/recall")
            ->assertNotFound();

        $this->assertSame('completed', $item->fresh()->status);
    }
}
