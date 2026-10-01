<?php

namespace Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Services\OrderPayment\OrderPaymentServiceInterface;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Gateways\Data\GatewayChargeRequest;
use Modules\Payment\Gateways\Drivers\ManualTerminalDriver;
use Modules\Payment\Gateways\Drivers\PineLabsPlutusDriver;
use Modules\Payment\Gateways\Drivers\RazorpayDriver;
use Modules\Payment\Gateways\PaymentGatewayManager;
use Modules\Payment\Services\Payment\PaymentServiceInterface;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Models\PosSession;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class PaymentGatewayTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
        Artisan::call('permission:sync-default-roles');
        // Realtime order broadcasts are irrelevant here and would try to reach a
        // live websocket server; route them to the null broadcaster.
        config(['broadcasting.default' => 'null']);
    }

    private function chargeRequest(array $overrides = []): GatewayChargeRequest
    {
        return new GatewayChargeRequest(
            amount: $overrides['amount'] ?? 250.50,
            currency: 'INR',
            orderReferenceNo: 'ORD-1',
            reference: $overrides['reference'] ?? '100200300',
            terminalId: $overrides['terminalId'] ?? null,
            metadata: $overrides['metadata'] ?? [],
        );
    }

    private function pineLabsConfig(): array
    {
        return [
            'base_url' => 'https://plutus.example.test',
            'merchant_id' => 'M123',
            'store_id' => 'S1',
            'client_id' => 'C1',
            'security_token' => 'tok-secret',
            'poll_timeout_seconds' => 5,
            'poll_interval_seconds' => 1,
        ];
    }

    private function razorpayConfig(array $overrides = []): array
    {
        return [
            'base_url' => 'https://api.razorpay.example.test/v1',
            'key_id' => 'rzp_test_key',
            'key_secret' => 'rzp_secret',
            'capture_authorized' => true,
            'require_signature' => true,
            'http_timeout_seconds' => 5,
            ...$overrides,
        ];
    }

    public function test_manual_driver_approves_with_reference_and_card_meta(): void
    {
        $result = (new ManualTerminalDriver())->charge($this->chargeRequest([
            'metadata' => ['approval_reference' => 'AUTH-9', 'card_last4' => '4242', 'card_scheme' => 'VISA'],
        ]));

        $this->assertTrue($result->isApproved());
        $this->assertSame('AUTH-9', $result->gatewayTransactionId);
        $this->assertSame('4242', $result->cardLast4);
    }

    public function test_manager_only_offers_configured_gateways(): void
    {
        $manager = new PaymentGatewayManager(['default' => 'manual', 'pinelabs' => []]);

        $this->assertTrue($manager->isConfigured('manual'));
        $this->assertFalse($manager->isConfigured('pinelabs'));
        $this->assertSame(['manual'], $manager->available());

        $this->expectException(\InvalidArgumentException::class);
        $manager->driver('pinelabs');
    }

    public function test_manager_offers_configured_razorpay_gateway(): void
    {
        $manager = new PaymentGatewayManager([
            'default' => 'manual',
            'pinelabs' => [],
            'razorpay' => $this->razorpayConfig(),
        ]);

        $this->assertTrue($manager->isConfigured('razorpay'));
        $this->assertContains('razorpay', $manager->available());
    }

    public function test_pinelabs_driver_charges_via_cloud_and_returns_card_details(): void
    {
        Http::fake([
            '*UploadBilledTransaction' => Http::response(['ResponseCode' => 0, 'ResponseMessage' => 'OK']),
            '*GetCloudBasedTransactionStatus' => Http::response([
                'ResponseCode' => 0,
                'ResponseMessage' => 'TXN APPROVED',
                'TransactionData' => [
                    ['Tag' => 'Approval Code', 'value' => '123456'],
                    ['Tag' => 'Card Number', 'value' => 'XXXXXXXXXXXX4242'],
                    ['Tag' => 'Acquirer Name', 'value' => 'VISA'],
                ],
            ]),
        ]);

        $result = (new PineLabsPlutusDriver($this->pineLabsConfig()))
            ->charge($this->chargeRequest(['amount' => 250.50]));

        $this->assertTrue($result->isApproved());
        $this->assertSame('123456', $result->approvalCode);
        $this->assertSame('4242', $result->cardLast4);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'UploadBilledTransaction')
                && $request['AmountInPaisa'] === 25050
                && $request['MerchantID'] === 'M123'
                && $request['SecurityToken'] === 'tok-secret';
        });
    }

    public function test_pinelabs_declined_transaction_is_not_approved(): void
    {
        Http::fake([
            '*UploadBilledTransaction' => Http::response(['ResponseCode' => 0]),
            '*GetCloudBasedTransactionStatus' => Http::response([
                'ResponseCode' => 9, 'ResponseMessage' => 'DECLINED',
            ]),
        ]);

        $result = (new PineLabsPlutusDriver($this->pineLabsConfig()))->charge($this->chargeRequest());

        $this->assertTrue($result->isDeclined());
    }

    public function test_razorpay_driver_captures_authorized_payment(): void
    {
        Http::fake([
            '*payments/pay_123/capture' => Http::response([
                'id' => 'pay_123',
                'status' => 'captured',
                'method' => 'upi',
            ]),
            '*payments/pay_123' => Http::response([
                'id' => 'pay_123',
                'status' => 'authorized',
                'method' => 'upi',
            ]),
        ]);

        $signature = hash_hmac('sha256', 'order_123|pay_123', 'rzp_secret');

        $result = (new RazorpayDriver($this->razorpayConfig()))
            ->charge($this->chargeRequest([
                'amount' => 250.50,
                'metadata' => [
                    'razorpay_payment_id' => 'pay_123',
                    'razorpay_order_id' => 'order_123',
                    'razorpay_signature' => $signature,
                ],
            ]));

        $this->assertTrue($result->isApproved());
        $this->assertSame('pay_123', $result->gatewayTransactionId);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'payments/pay_123/capture')
                && $request['amount'] === 25050
                && $request['currency'] === 'INR';
        });
    }

    public function test_razorpay_driver_rejects_invalid_signature(): void
    {
        Http::fake();

        $result = (new RazorpayDriver($this->razorpayConfig()))
            ->charge($this->chargeRequest([
                'metadata' => [
                    'razorpay_payment_id' => 'pay_123',
                    'razorpay_order_id' => 'order_123',
                    'razorpay_signature' => 'bad-signature',
                ],
            ]));

        $this->assertFalse($result->isApproved());
        $this->assertSame('Razorpay signature verification failed.', $result->message);
        Http::assertNothingSent();
    }

    public function test_process_payment_records_gateway_fields_on_approval(): void
    {
        $branch = $this->makeBranch(['payment_methods' => [PaymentMethod::Card->value]]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Sanctum::actingAs($user, ['*'], 'api');

        $order = $this->makeOrder($branch, [
            'type' => OrderType::DineIn,
            'currency' => 'INR',
            'currency_rate' => 1,
            'total' => 250.50,
            'subtotal' => 250.50,
        ]);

        $payment = app(PaymentServiceInterface::class)->processPayment($order, [
            'amount' => 250.50,
            'method' => PaymentMethod::Card->value,
            'transaction_id' => 'AUTH-77',
            'gateway' => 'manual',
            'gateway_data' => ['card_last4' => '4242', 'card_scheme' => 'VISA'],
        ]);

        $this->assertSame('manual', $payment->gateway);
        $this->assertSame('AUTH-77', $payment->gateway_transaction_id);
        $this->assertSame('AUTH-77', $payment->transaction_id);
        $this->assertSame('4242', $payment->meta['card_last4'] ?? null);
        $this->assertSame(PaymentStatus::Completed, $payment->status);
    }

    public function test_process_payment_records_failed_gateway_attempt_without_settling_order(): void
    {
        config(['payment.gateways.razorpay' => $this->razorpayConfig()]);
        app()->forgetInstance(PaymentGatewayManager::class);
        Http::fake();

        $branch = $this->makeBranch(['payment_methods' => [PaymentMethod::MobileWallet->value]]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Sanctum::actingAs($user, ['*'], 'api');

        $order = $this->makeOrder($branch, [
            'type' => OrderType::Takeaway,
            'currency' => 'INR',
            'currency_rate' => 1,
            'total' => 250.50,
            'subtotal' => 250.50,
            'due_amount' => 250.50,
        ]);

        try {
            app(PaymentServiceInterface::class)->processPayment($order, [
                'amount' => 250.50,
                'method' => PaymentMethod::MobileWallet->value,
                'gateway' => 'razorpay',
                'gateway_data' => [
                    'razorpay_payment_id' => 'pay_bad',
                    'razorpay_order_id' => 'order_bad',
                    'razorpay_signature' => 'bad-signature',
                ],
            ]);
            $this->fail('Expected gateway validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('gateway', $exception->errors());
        }

        $payment = $order->payments()->latest('id')->firstOrFail();
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('razorpay', $payment->gateway);
        $this->assertSame('Razorpay signature verification failed.', $payment->gateway_response['message'] ?? null);

        $order->refresh();
        $order->refreshDueAmount();
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
    }

    public function test_pos_order_payment_records_razorpay_gateway_fields(): void
    {
        config(['payment.gateways.razorpay' => $this->razorpayConfig()]);
        app()->forgetInstance(PaymentGatewayManager::class);

        Http::fake([
            '*payments/pay_pos_123' => Http::response([
                'id' => 'pay_pos_123',
                'status' => 'captured',
                'method' => 'upi',
            ]),
        ]);

        $branch = $this->makeBranch(['payment_methods' => [
            PaymentMethod::MobileWallet->value,
            PaymentMethod::Cash->value,
        ]]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Sanctum::actingAs($user, ['*'], 'api');

        $register = PosRegister::factory()->create([
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $session = PosSession::factory()->create([
            'branch_id' => $branch->id,
            'pos_register_id' => $register->id,
            'opened_by' => $user->id,
            'status' => PosSessionStatus::Open->value,
            'opened_at' => now(),
        ]);
        $order = $this->makeOrder($branch, [
            'type' => OrderType::Takeaway,
            'currency' => 'INR',
            'currency_rate' => 1,
            'subtotal' => 250.50,
            'total' => 250.50,
            'due_amount' => 250.50,
            'status' => OrderStatus::Pending,
            'kitchen_display' => false,
        ]);
        $order->syncApplicableOrderTaxes();
        $amountDue = round($order->fresh()->due_amount->amount(), 2);

        $signature = hash_hmac('sha256', 'order_pos_123|pay_pos_123', 'rzp_secret');

        app(OrderPaymentServiceInterface::class)->storePayment($order->id, [
            'register_id' => $register->id,
            'session_id' => $session->id,
            'payment_mode' => 'full',
            'with_print' => false,
            'payments' => [[
                'method' => PaymentMethod::MobileWallet->value,
                'amount' => $amountDue,
                'gateway' => 'razorpay',
                'gateway_data' => [
                    'razorpay_payment_id' => 'pay_pos_123',
                    'razorpay_order_id' => 'order_pos_123',
                    'razorpay_signature' => $signature,
                ],
            ]],
        ]);

        $payment = $order->payments()->latest('id')->firstOrFail();
        $this->assertSame('razorpay', $payment->gateway);
        $this->assertSame('pay_pos_123', $payment->gateway_transaction_id);
        $this->assertSame('pay_pos_123', $payment->transaction_id);
        $this->assertSame('upi', $payment->meta['card_scheme'] ?? null);
        $this->assertSame(PaymentStatus::Completed, $payment->status);
        $order->refresh();
        $this->assertTrue($order->payment_status->isPaid());
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertTrue((bool) $order->kitchen_display);
    }

    public function test_pos_order_payment_records_failed_gateway_attempt_without_settling_order(): void
    {
        config(['payment.gateways.razorpay' => $this->razorpayConfig()]);
        app()->forgetInstance(PaymentGatewayManager::class);
        Http::fake();

        $branch = $this->makeBranch(['payment_methods' => [
            PaymentMethod::MobileWallet->value,
            PaymentMethod::Cash->value,
        ]]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Sanctum::actingAs($user, ['*'], 'api');

        $register = PosRegister::factory()->create([
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $session = PosSession::factory()->create([
            'branch_id' => $branch->id,
            'pos_register_id' => $register->id,
            'opened_by' => $user->id,
            'status' => PosSessionStatus::Open->value,
            'opened_at' => now(),
        ]);
        $order = $this->makeOrder($branch, [
            'type' => OrderType::Takeaway,
            'currency' => 'INR',
            'currency_rate' => 1,
            'subtotal' => 250.50,
            'total' => 250.50,
            'due_amount' => 250.50,
        ]);
        $order->syncApplicableOrderTaxes();
        $amountDue = round($order->fresh()->due_amount->amount(), 2);

        try {
            app(OrderPaymentServiceInterface::class)->storePayment($order->id, [
                'register_id' => $register->id,
                'session_id' => $session->id,
                'payment_mode' => 'full',
                'with_print' => false,
                'payments' => [[
                    'method' => PaymentMethod::MobileWallet->value,
                    'amount' => $amountDue,
                    'gateway' => 'razorpay',
                    'gateway_data' => [
                        'razorpay_payment_id' => 'pay_pos_bad',
                        'razorpay_order_id' => 'order_pos_bad',
                        'razorpay_signature' => 'bad-signature',
                    ],
                ]],
            ]);
            $this->fail('Expected gateway validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payments.0.gateway', $exception->errors());
        }

        $payment = $order->payments()->latest('id')->firstOrFail();
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('razorpay', $payment->gateway);

        $order->refresh();
        $order->refreshDueAmount();
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
    }
}
