<?php

namespace Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Events\OrderPaid;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Enums\PaymentType;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * Billing depth — Complimentary (NC) bill. Settling an order's remaining due as a
 * no-charge bill records a `complimentary` payment (kept out of cash/card revenue),
 * captures the audit reason, and marks the order fully paid.
 *
 * Not gated with #[RequiresPhpExtension('pdo_sqlite')] so it runs against the
 * configured DB connection (MySQL scratch DB locally).
 */
class ComplimentaryBillApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
        // OrderPaid is a ShouldBroadcast event; fake it so settling an order in
        // tests doesn't attempt a real Reverb/Pusher network call.
        Event::fake([OrderPaid::class]);
    }

    public function test_complimentary_settles_order_and_records_comp_payment(): void
    {
        $branch = $this->makeBranch();
        $order = $this->makeOrder($branch); // total 100, unpaid
        $order->refreshDueAmount();
        $this->assertEquals(100, round($order->fresh()->due_amount->amount(), 2));

        $manager = $this->actingAsUserWithPermissions(['admin.orders.complimentary']);
        $manager->forceFill(['branch_id' => $branch->id])->save();
        $manager->refresh();

        $this->postJson('/api/v1/payments/complimentary', [
            'order_id' => $order->id,
            'reason' => 'VIP guest - on the house',
        ], ['Idempotency-Key' => 'comp-test-settle'])->assertCreated();

        // Order is fully settled, with zero due.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'due_amount' => 0,
            'payment_status' => OrderPaymentStatus::Paid->value,
        ]);

        // A complimentary payment is recorded — NOT cash/card — for the full amount.
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'method' => PaymentMethod::Complimentary->value,
            'type' => PaymentType::Payment->value,
            'amount' => 100,
        ]);

        // Reason is captured for audit.
        $payment = $order->payments()->where('method', PaymentMethod::Complimentary->value)->first();
        $this->assertEquals('VIP guest - on the house', $payment->meta['reason'] ?? null);
    }

    public function test_complimentary_requires_permission(): void
    {
        $branch = $this->makeBranch();
        $order = $this->makeOrder($branch);
        $order->refreshDueAmount();

        $user = $this->actingAsUserWithPermissions(['admin.orders.index']);
        $user->forceFill(['branch_id' => $branch->id])->save();
        $user->refresh();

        $this->postJson('/api/v1/payments/complimentary', [
            'order_id' => $order->id,
            'reason' => 'should be blocked',
        ], ['Idempotency-Key' => 'comp-test-forbidden'])->assertForbidden();
    }

    public function test_complimentary_rejects_already_settled_order(): void
    {
        $branch = $this->makeBranch();
        $order = $this->makeOrder($branch);

        // Pay it off first so there is nothing due.
        $order->payments()->create([
            'order_reference_no' => $order->reference_no ?? ('REF-' . $order->id),
            'branch_id' => $branch->id,
            'method' => PaymentMethod::Cash->value,
            'type' => PaymentType::Payment->value,
            'amount' => 100,
            'currency' => 'INR',
            'currency_rate' => 1,
            'received_at' => now(),
        ]);
        $order->refreshDueAmount();

        $manager = $this->actingAsUserWithPermissions(['admin.orders.complimentary']);
        $manager->forceFill(['branch_id' => $branch->id])->save();
        $manager->refresh();

        $this->postJson('/api/v1/payments/complimentary', [
            'order_id' => $order->id,
            'reason' => 'too late',
        ], ['Idempotency-Key' => 'comp-test-settled'])->assertStatus(422);
    }

    // ── Hardening: bad / hard cases ─────────────────────────────────────────

    public function test_complimentary_rejects_missing_reason(): void
    {
        $branch = $this->makeBranch();
        $order = $this->makeOrder($branch);

        $manager = $this->actingAsUserWithPermissions(['admin.orders.complimentary']);
        $manager->forceFill(['branch_id' => $branch->id])->save();
        $manager->refresh();

        $this->postJson('/api/v1/payments/complimentary', [
            'order_id' => $order->id,
        ], ['Idempotency-Key' => 'comp-no-reason'])->assertStatus(422);
    }

    public function test_complimentary_unknown_order_is_handled_cleanly(): void
    {
        $manager = $this->actingAsUserWithPermissions(['admin.orders.complimentary']);
        $manager->forceFill(['branch_id' => $this->makeBranch()->id])->save();
        $manager->refresh();

        // A non-existent order must not 500 — it should be a clean client error.
        $response = $this->postJson('/api/v1/payments/complimentary', [
            'order_id' => 999999999,
            'reason' => 'ghost order',
        ], ['Idempotency-Key' => 'comp-404']);

        $this->assertLessThan(500, $response->status());
        $this->assertContains($response->status(), [404, 422]);
    }
}
