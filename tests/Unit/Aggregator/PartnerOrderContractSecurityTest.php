<?php

namespace Tests\Unit\Aggregator;

use Mockery;
use Modules\Aggregator\Http\Controllers\Api\V1\PartnerOrderController;
use Modules\Aggregator\Services\PartnerApi\PartnerResourceService;
use Modules\Order\Services\OrderCreate\CreateOrderService;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use ReflectionMethod;
use Tests\TestCase;

class PartnerOrderContractSecurityTest extends TestCase
{
    public function test_order_payload_rejects_client_controlled_financial_fields(): void
    {
        $controller = new PartnerOrderController(
            Mockery::mock(PartnerResourceService::class),
            Mockery::mock(CreateOrderService::class),
            Mockery::mock(EffectiveTenantEntitlementService::class),
        );
        $method = new ReflectionMethod($controller, 'unknownOrderFields');

        $unknown = $method->invoke($controller, [
            'external_order_id' => 'partner-1',
            'branch_id' => 'uuid',
            'order_type' => 'delivery',
            'customer' => ['name' => 'Test', 'phone' => '919999999999', 'credit' => 5000],
            'items' => [['product_id' => 'uuid', 'quantity' => 1, 'price' => 1, 'total' => 1]],
            'InvoiceInfo' => ['TotalTax' => 0],
            'PaymentThrough' => 'COD',
        ]);

        $this->assertEqualsCanonicalizing([
            'customer.credit',
            'items.0.price',
            'items.0.total',
            'InvoiceInfo',
            'PaymentThrough',
        ], $unknown);
    }

    public function test_public_order_routes_accept_only_uuids(): void
    {
        $routes = file_get_contents(base_path('Modules/Aggregator/routes/api/v1.php'));
        $controller = file_get_contents(base_path('Modules/Aggregator/app/Http/Controllers/Api/V1/PartnerOrderController.php'));

        $this->assertStringContainsString("->whereUuid('orderId')", $routes);
        $this->assertStringContainsString("->where('partner_id', \$context->partner->id)", $controller);
        $this->assertStringContainsString("->where('tenant_id', \$context->tenantId())", $controller);
        $this->assertStringContainsString("'id' => \$mapping->uuid", $controller);
        $this->assertStringNotContainsString("'order_number' =>", $controller);
        $this->assertStringContainsString('partner.scope:orders:status', $routes);
        $this->assertStringContainsString('partner.scope:payments:write', $routes);
        $this->assertStringContainsString('partner.idempotency', $routes);
        $this->assertStringContainsString('$order->due_amount->amount()', $controller);
        $this->assertStringContainsString("'customer_id' => \$customer->id", $controller);
        $this->assertStringContainsString("'pos_register_id' => null", $controller);
        $this->assertStringNotContainsString("'pos_register_id' => 0", $controller);

        $createOrder = file_get_contents(base_path('Modules/Order/app/Services/OrderCreate/CreateOrderService.php'));
        $this->assertStringContainsString('?int   $posRegisterId', $createOrder);
        $this->assertStringContainsString("isset(\$data['pos_register_id']) ? (int) \$data['pos_register_id'] : null", $createOrder);
        $this->assertStringContainsString("->where('tenant_id', \$context->tenantId())", $controller);
        $this->assertStringContainsString('DefaultRole::Customer->value', $controller);
        $this->assertStringNotContainsString('Partner customer:', $controller);
        $this->assertStringContainsString("->whereColumn('orders.branch_id', 'partner_api_order_mappings.branch_id')", $controller);

        $idempotency = file_get_contents(base_path('Modules/Aggregator/app/Http/Middleware/EnsurePartnerIdempotency.php'));
        $this->assertStringContainsString("['credential_id' => \$context->credential->id, 'key_hash' => \$keyHash]", $idempotency);
        $this->assertStringContainsString('IDEMPOTENCY_CONFLICT', $idempotency);
        $this->assertStringContainsString("->where('status', '!=', 'completed')", $idempotency);

        $model = file_get_contents(base_path('Modules/Aggregator/app/Models/PartnerApiIdempotency.php'));
        $this->assertStringContainsString("'response_body' => 'encrypted'", $model);

        $authentication = file_get_contents(base_path('Modules/Aggregator/app/Http/Middleware/AuthenticatePartnerRequest.php'));
        $this->assertStringContainsString('(int) $credential->partner->tenant_id !== (int) $credential->tenant_id', $authentication);
        $this->assertStringContainsString('$credential->orders_per_minute', $authentication);
        $this->assertStringContainsString('$credential->burst_limit', $authentication);
        $this->assertStringContainsString('ORDER_RATE_LIMITED', $authentication);
        $this->assertStringContainsString('ORDER_BURST_LIMITED', $authentication);
        $this->assertStringContainsString('$this->tenantContext->set($tenant);', $authentication);
        $this->assertMatchesRegularExpression(
            '/tenantContext->set\(\$tenant\);\s*try \{.*?return \$next\(\$request\);\s*\} finally \{\s*\$this->tenantContext->clear\(\);/s',
            $authentication,
        );

        $this->assertStringContainsString('catch (QueryException $exception)', $idempotency);
        $this->assertStringContainsString('idempotency_lock_seconds', $idempotency);
    }

    public function test_nondelivery_partner_charge_is_bounded_and_delivery_fee_is_server_controlled(): void
    {
        $controller = new PartnerOrderController(
            Mockery::mock(PartnerResourceService::class),
            Mockery::mock(CreateOrderService::class),
            Mockery::mock(EffectiveTenantEntitlementService::class),
        );
        $method = new ReflectionMethod($controller, 'unknownOrderFields');

        $this->assertSame([], $method->invoke($controller, [
            'external_order_id' => 'partner-1', 'branch_id' => 'uuid', 'order_type' => 'pickup',
            'customer' => ['name' => 'Test', 'phone' => '919999999999'],
            'items' => [['product_id' => 'uuid', 'quantity' => 1]], 'additional_payment' => ['delivery_charges' => 10, 'service_charges' => 15.50],
        ]));

        $createOrder = file_get_contents(base_path('Modules/Order/app/Services/OrderCreate/CreateOrderService.php'));
        $this->assertStringContainsString('$additionalAmount > round($subtotal', $createOrder);
        $this->assertStringContainsString("data['additional_payments']", $createOrder);
        $this->assertStringContainsString('$subtotal + $totalTaxes + $additionalAmount', $createOrder);
        $controllerSource = file_get_contents(base_path('Modules/Aggregator/app/Http/Controllers/Api/V1/PartnerOrderController.php'));
        $this->assertStringContainsString('DELIVERY_FEE_SERVER_CONTROLLED', $controllerSource);
    }
}
