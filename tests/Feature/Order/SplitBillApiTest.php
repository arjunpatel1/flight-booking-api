<?php

namespace Tests\Feature\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Order\Events\OrderPaid;
use Modules\Order\Models\Order;
use Modules\Product\Models\Product;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * Billing depth — Split bill (by items). Moving line items into a new child bill
 * must reconcile: parent + child totals equal the original, items follow, and the
 * child is independently payable. Tax/total recompute runs through the order's own
 * recalculate(), not hand-rolled math.
 *
 * Not gated with #[RequiresPhpExtension('pdo_sqlite')] so it runs against the
 * configured DB connection (MySQL scratch DB locally).
 */
class SplitBillApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
        Event::fake([OrderPaid::class]);
    }

    private function product(\Modules\Branch\Models\Branch $branch): Product
    {
        return Product::factory()->create(['menu_id' => $this->makeMenu($branch)->id]);
    }

    private function addItem(Order $order, Product $product, float $amount): \Modules\Order\Models\OrderProduct
    {
        return $order->products()->create([
            'product_id' => $product->id,
            'currency' => 'INR',
            'currency_rate' => 1,
            'unit_price' => $amount,
            'quantity' => 1,
            'subtotal' => $amount,
            'tax_total' => 0,
            'total' => $amount,
            'cost_price' => 0,
            'revenue' => $amount,
        ]);
    }

    public function test_split_moves_items_to_child_bill_and_reconciles_totals(): void
    {
        $branch = $this->makeBranch();
        $order = $this->makeOrder($branch);

        $itemA = $this->addItem($order, $this->product($branch), 60);
        $itemB = $this->addItem($order, $this->product($branch), 40);
        $order->recalculate(); // parent total = 100

        $manager = $this->actingAsUserWithPermissions(['admin.orders.split']);
        $manager->forceFill(['branch_id' => $branch->id])->save();
        $manager->refresh();

        $this->postJson("/api/v1/orders/{$order->id}/split", [
            'items' => [$itemB->id],
        ], ['Idempotency-Key' => 'split-ok'])->assertCreated();

        $order->refresh();
        $child = Order::query()->where('split_from_order_id', $order->id)->firstOrFail();

        // Items followed the split.
        $this->assertDatabaseHas('order_products', ['id' => $itemA->id, 'order_id' => $order->id]);
        $this->assertDatabaseHas('order_products', ['id' => $itemB->id, 'order_id' => $child->id]);

        // Totals reconcile: parent (60) + child (40) == original (100).
        $this->assertEquals(60, round($order->total->amount(), 2));
        $this->assertEquals(40, round($child->total->amount(), 2));
        $this->assertEquals(
            100,
            round($order->total->amount() + $child->total->amount(), 2)
        );

        // Child is its own independently-payable bill.
        $this->assertEquals($order->id, $child->split_from_order_id);
        $this->assertEquals(40, round($child->due_amount->amount(), 2));
        $this->assertNotEquals($order->reference_no, $child->reference_no);
    }

    public function test_split_rejects_moving_all_items(): void
    {
        $branch = $this->makeBranch();
        $order = $this->makeOrder($branch);
        $itemA = $this->addItem($order, $this->product($branch), 60);
        $itemB = $this->addItem($order, $this->product($branch), 40);
        $order->recalculate();

        $manager = $this->actingAsUserWithPermissions(['admin.orders.split']);
        $manager->forceFill(['branch_id' => $branch->id])->save();
        $manager->refresh();

        $this->postJson("/api/v1/orders/{$order->id}/split", [
            'items' => [$itemA->id, $itemB->id],
        ], ['Idempotency-Key' => 'split-all'])->assertStatus(422);

        // No child bill was created.
        $this->assertDatabaseMissing('orders', ['split_from_order_id' => $order->id]);
    }

    public function test_split_requires_permission(): void
    {
        $branch = $this->makeBranch();
        $order = $this->makeOrder($branch);
        $itemA = $this->addItem($order, $this->product($branch), 60);
        $itemB = $this->addItem($order, $this->product($branch), 40);
        $order->recalculate();

        $user = $this->actingAsUserWithPermissions(['admin.orders.index']);
        $user->forceFill(['branch_id' => $branch->id])->save();
        $user->refresh();

        $this->postJson("/api/v1/orders/{$order->id}/split", [
            'items' => [$itemB->id],
        ], ['Idempotency-Key' => 'split-forbidden'])->assertForbidden();
    }

    // ── Hardening: bad / hard cases ─────────────────────────────────────────

    public function test_split_rejects_items_belonging_to_another_order(): void
    {
        $branch = $this->makeBranch();
        $order = $this->makeOrder($branch);
        $this->addItem($order, $this->product($branch), 60);
        $this->addItem($order, $this->product($branch), 40);
        $order->recalculate();

        // An item from a different order must not be movable into this split.
        $otherOrder = $this->makeOrder($branch);
        $foreignItem = $this->addItem($otherOrder, $this->product($branch), 25);

        $manager = $this->actingAsUserWithPermissions(['admin.orders.split']);
        $manager->forceFill(['branch_id' => $branch->id])->save();
        $manager->refresh();

        $this->postJson("/api/v1/orders/{$order->id}/split", [
            'items' => [$foreignItem->id],
        ], ['Idempotency-Key' => 'split-foreign'])->assertStatus(422);

        $this->assertDatabaseMissing('orders', ['split_from_order_id' => $order->id]);
        // The foreign item stayed put.
        $this->assertDatabaseHas('order_products', ['id' => $foreignItem->id, 'order_id' => $otherOrder->id]);
    }

    public function test_split_unknown_order_returns_404(): void
    {
        // Use a real (existing) item so request validation passes; the controller's
        // findOrFail on the bogus order id is what must produce the 404.
        $branch = $this->makeBranch();
        $realOrder = $this->makeOrder($branch);
        $realItem = $this->addItem($realOrder, $this->product($branch), 30);

        $manager = $this->actingAsUserWithPermissions(['admin.orders.split']);
        $manager->forceFill(['branch_id' => $branch->id])->save();
        $manager->refresh();

        $this->postJson('/api/v1/orders/999999999/split', [
            'items' => [$realItem->id],
        ], ['Idempotency-Key' => 'split-404'])->assertNotFound();
    }

    public function test_split_is_idempotent_on_replay(): void
    {
        $branch = $this->makeBranch();
        $order = $this->makeOrder($branch);
        $this->addItem($order, $this->product($branch), 60);
        $itemB = $this->addItem($order, $this->product($branch), 40);
        $order->recalculate();

        $manager = $this->actingAsUserWithPermissions(['admin.orders.split']);
        $manager->forceFill(['branch_id' => $branch->id])->save();
        $manager->refresh();

        $headers = ['Idempotency-Key' => 'split-replay-001'];
        $this->postJson("/api/v1/orders/{$order->id}/split", ['items' => [$itemB->id]], $headers)->assertCreated();
        // Replaying the same idempotency key must NOT create a second child bill.
        $this->postJson("/api/v1/orders/{$order->id}/split", ['items' => [$itemB->id]], $headers);

        $this->assertSame(1, Order::query()->where('split_from_order_id', $order->id)->count());
    }
}
