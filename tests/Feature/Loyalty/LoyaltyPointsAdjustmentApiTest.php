<?php

namespace Tests\Feature\Loyalty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * CRM depth — manual loyalty points adjustment. Staff can credit/debit points for
 * goodwill or correction, recorded as an `adjust` transaction. A debit cannot push
 * the balance negative.
 *
 * Ungated (no #[RequiresPhpExtension('pdo_sqlite')]) so it runs on the MySQL scratch DB.
 */
class LoyaltyPointsAdjustmentApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    private function loyaltyCustomer(int $balance = 100, int $lifetime = 500): LoyaltyCustomer
    {
        $program = LoyaltyProgram::factory()->create(['is_active' => true]);

        return LoyaltyCustomer::factory()->create([
            'customer_id' => User::factory()->create()->id,
            'loyalty_program_id' => $program->id,
            'points_balance' => $balance,
            'lifetime_points' => $lifetime,
        ]);
    }

    public function test_credit_and_debit_adjust_balance_and_record_transactions(): void
    {
        $lc = $this->loyaltyCustomer(balance: 100, lifetime: 500);
        $this->actingAsUserWithPermissions(['admin.loyalty_customers.adjust']);

        // Credit 50 → balance 150, lifetime 550.
        $this->postJson("/api/v1/loyalty-customers/{$lc->id}/adjust-points", [
            'points' => 50,
            'reason' => 'Goodwill gesture',
        ])->assertOk();

        $lc->refresh();
        $this->assertSame(150, $lc->points_balance);
        $this->assertSame(550, $lc->lifetime_points);
        $this->assertDatabaseHas('loyalty_transactions', [
            'loyalty_customer_id' => $lc->id,
            'type' => 'adjust',
            'points' => 50,
        ]);

        // Debit 30 → balance 120, lifetime unchanged (debits don't reduce lifetime).
        $this->postJson("/api/v1/loyalty-customers/{$lc->id}/adjust-points", [
            'points' => -30,
        ])->assertOk();

        $lc->refresh();
        $this->assertSame(120, $lc->points_balance);
        $this->assertSame(550, $lc->lifetime_points);
    }

    public function test_debit_cannot_make_balance_negative(): void
    {
        $lc = $this->loyaltyCustomer(balance: 40);
        $this->actingAsUserWithPermissions(['admin.loyalty_customers.adjust']);

        $this->postJson("/api/v1/loyalty-customers/{$lc->id}/adjust-points", [
            'points' => -100,
        ])->assertStatus(422);

        $this->assertSame(40, $lc->fresh()->points_balance);
        $this->assertDatabaseMissing('loyalty_transactions', [
            'loyalty_customer_id' => $lc->id,
            'type' => 'adjust',
        ]);
    }

    public function test_adjust_requires_permission(): void
    {
        $lc = $this->loyaltyCustomer();
        $this->actingAsUserWithPermissions(['admin.loyalty_customers.index']);

        $this->postJson("/api/v1/loyalty-customers/{$lc->id}/adjust-points", [
            'points' => 50,
        ])->assertForbidden();
    }

    // ── Hardening: bad / hard cases ─────────────────────────────────────────

    public function test_adjust_unknown_customer_returns_404(): void
    {
        $this->actingAsUserWithPermissions(['admin.loyalty_customers.adjust']);

        $this->postJson('/api/v1/loyalty-customers/999999999/adjust-points', [
            'points' => 50,
        ])->assertNotFound();
    }

    public function test_adjust_rejects_non_integer_points(): void
    {
        $lc = $this->loyaltyCustomer();
        $this->actingAsUserWithPermissions(['admin.loyalty_customers.adjust']);

        $this->postJson("/api/v1/loyalty-customers/{$lc->id}/adjust-points", [
            'points' => 'not-a-number',
        ])->assertStatus(422);
    }

    public function test_large_credit_then_full_debit_reconciles_exactly(): void
    {
        // Stress the running balance with a large credit and an exact-balance debit.
        $lc = $this->loyaltyCustomer(balance: 0, lifetime: 0);
        $this->actingAsUserWithPermissions(['admin.loyalty_customers.adjust']);

        $this->postJson("/api/v1/loyalty-customers/{$lc->id}/adjust-points", ['points' => 1000000])->assertOk();
        $this->assertSame(1000000, $lc->fresh()->points_balance);

        $this->postJson("/api/v1/loyalty-customers/{$lc->id}/adjust-points", ['points' => -1000000])->assertOk();
        $this->assertSame(0, $lc->fresh()->points_balance);

        // One more point of debit must be rejected (balance is exactly zero).
        $this->postJson("/api/v1/loyalty-customers/{$lc->id}/adjust-points", ['points' => -1])->assertStatus(422);
        $this->assertSame(0, $lc->fresh()->points_balance);
    }
}
