<?php

namespace Tests\Feature\Report;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Order\Enums\OrderStatus;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Enums\PaymentType;
use Modules\Payment\Models\Payment;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * Feature 6 — Waiter Clearance. Covers the live expected-cash preview and the
 * settlement record (expected vs settled difference).
 *
 * Not gated with #[RequiresPhpExtension('pdo_sqlite')] so it runs against the
 * configured DB connection (MySQL scratch DB locally).
 */
class WaiterSettlementApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    private function cashOrderForWaiter(int $branchId, int $waiterId, float $amount): void
    {
        $order = $this->makeOrder($branchId === 0 ? $this->makeBranch() : \Modules\Branch\Models\Branch::find($branchId), [
            'waiter_id' => $waiterId,
            'status' => OrderStatus::Completed,
        ]);

        Payment::query()->create([
            'order_id' => $order->id,
            'order_reference_no' => $order->reference_no ?? ('REF-' . $order->id),
            'branch_id' => $branchId,
            'method' => PaymentMethod::Cash->value,
            'type' => PaymentType::Payment->value,
            'amount' => $amount,
            'currency' => 'INR',
            'currency_rate' => 1,
            'received_at' => now(),
        ]);
    }

    public function test_preview_returns_live_expected_cash_for_a_waiter(): void
    {
        $branch = $this->makeBranch();
        $waiter = User::factory()->create(['branch_id' => $branch->id]);
        $this->cashOrderForWaiter($branch->id, $waiter->id, 250);
        $this->cashOrderForWaiter($branch->id, $waiter->id, 150);

        $manager = $this->actingAsUserWithPermissions(['admin.waiter_settlements.index']);
        $manager->forceFill(['branch_id' => $branch->id])->save();
        $manager->refresh();

        $response = $this->getJson(
            '/api/v1/waiter-settlements/preview?waiter_id=' . $waiter->id .
            '&business_date=' . now()->toDateString()
        )->assertOk();

        // 250 + 150 = 400 expected cash.
        $this->assertEquals(400, $response->json('body.expected_amount.amount'));
    }

    public function test_store_records_settlement_with_difference(): void
    {
        $branch = $this->makeBranch();
        $waiter = User::factory()->create(['branch_id' => $branch->id]);
        $this->cashOrderForWaiter($branch->id, $waiter->id, 300);

        $manager = $this->actingAsUserWithPermissions([
            'admin.waiter_settlements.index',
            'admin.waiter_settlements.create',
        ]);
        $manager->forceFill(['branch_id' => $branch->id])->save();
        $manager->refresh();

        // Waiter hands over 290 against an expected 300 → difference -10.
        $this->postJson('/api/v1/waiter-settlements', [
            'waiter_id' => $waiter->id,
            'business_date' => now()->toDateString(),
            'settled_amount' => 290,
        ])->assertCreated();

        $this->assertDatabaseHas('waiter_collection_settlements', [
            'waiter_id' => $waiter->id,
            'branch_id' => $branch->id,
            'expected_amount' => 300,
            'settled_amount' => 290,
            'difference_amount' => -10,
            'settled_by' => $manager->id,
            'status' => 'settled',
        ]);
    }
}
