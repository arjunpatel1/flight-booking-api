<?php

namespace Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Order\Enums\OrderType;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Models\PosSession;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class PaymentOwnershipApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
        Artisan::call('permission:sync-default-roles');
    }

    public function test_waiter_cannot_open_or_submit_payment_for_another_waiters_order(): void
    {
        $branch = $this->makeBranch(['payment_methods' => [PaymentMethod::Cash->value]]);
        $owner = $this->waiter($branch->id);
        $otherWaiter = $this->waiter($branch->id);
        $order = $this->makeOrder($branch, [
            'type' => OrderType::DineIn,
            'waiter_id' => $owner->id,
        ]);
        $order->forceFill(['created_by' => $owner->id])->save();
        [$register, $session] = $this->openSession($branch->id, $otherWaiter->id);

        Sanctum::actingAs($otherWaiter, ['*'], 'api');

        $this->getJson("/api/v1/orders/{$order->id}/payments/meta")
            ->assertForbidden()
            ->assertJsonPath('message', __('order::messages.order_payment_owner_not_allowed'));

        $this->withHeader('Idempotency-Key', 'payment-owner-denied')
            ->postJson("/api/v1/orders/{$order->id}/payments", [
                'payments' => [[
                    'method' => PaymentMethod::Cash->value,
                    'amount' => 100,
                ]],
                'payment_mode' => 'full',
                'with_print' => false,
                'customer_given_amount' => 100,
                'change_return' => 0,
                'register_id' => $register->id,
                'session_id' => $session->id,
            ])->assertForbidden();

        $this->assertDatabaseMissing('payments', ['order_id' => $order->id]);
    }

    public function test_waiter_can_pay_own_direct_order_using_created_by_ownership(): void
    {
        $branch = $this->makeBranch(['payment_methods' => [PaymentMethod::Cash->value]]);
        $waiter = $this->waiter($branch->id);
        $order = $this->makeOrder($branch, [
            'type' => OrderType::Takeaway,
            'waiter_id' => null,
        ]);
        $order->forceFill(['created_by' => $waiter->id])->save();

        Sanctum::actingAs($waiter, ['*'], 'api');

        $this->getJson("/api/v1/orders/{$order->id}/payments/meta")
            ->assertOk()
            ->assertJsonCount(1, 'body.payment_methods')
            ->assertJsonPath('body.payment_methods.0.id', PaymentMethod::Cash->value);
    }

    public function test_cashier_can_open_payment_for_any_order_in_their_branch(): void
    {
        $branch = $this->makeBranch(['payment_methods' => [PaymentMethod::Cash->value]]);
        $owner = $this->waiter($branch->id);
        $cashier = User::factory()->create([
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $cashier->syncRoles([DefaultRole::Cashier->value]);
        $order = $this->makeOrder($branch, [
            'waiter_id' => $owner->id,
        ]);
        $order->forceFill(['created_by' => $owner->id])->save();

        Sanctum::actingAs($cashier, ['*'], 'api');

        $this->getJson("/api/v1/orders/{$order->id}/payments/meta")->assertOk();
    }

    private function waiter(int $branchId): User
    {
        $waiter = User::factory()->create([
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
        $waiter->syncRoles([DefaultRole::Waiter->value]);

        return $waiter;
    }

    /** @return array{PosRegister, PosSession} */
    private function openSession(int $branchId, int $userId): array
    {
        $register = PosRegister::factory()->create([
            'branch_id' => $branchId,
            'created_by' => $userId,
            'is_active' => true,
        ]);
        $session = PosSession::factory()->create([
            'branch_id' => $branchId,
            'pos_register_id' => $register->id,
            'opened_by' => $userId,
            'created_by' => $userId,
            'opened_at' => now(),
            'status' => PosSessionStatus::Open->value,
        ]);

        return [$register, $session];
    }
}
