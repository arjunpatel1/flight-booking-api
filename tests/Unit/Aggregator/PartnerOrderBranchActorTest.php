<?php

namespace Tests\Unit\Aggregator;

use PHPUnit\Framework\TestCase;

class PartnerOrderBranchActorTest extends TestCase
{
    public function test_partner_order_creation_selects_an_actor_for_the_mapped_branch(): void
    {
        $controller = file_get_contents(__DIR__.'/../../../Modules/Aggregator/app/Http/Controllers/Api/V1/PartnerOrderController.php');

        $this->assertStringContainsString('$this->tenantActor($context, (int) $branch->id)', $controller);
        $this->assertStringContainsString('->where(\'branch_id\', $branchId)', $controller);
        $this->assertStringContainsString("->orWhereNull('branch_id')", $controller);

        $service = file_get_contents(__DIR__.'/../../../Modules/Order/app/Services/OrderCreate/CreateOrderService.php');
        $this->assertStringContainsString('->withoutGlobalScopes()', $service);
        $this->assertStringContainsString("->where('branches.tenant_id', \$branch->tenant_id)", $service);
        $this->assertStringContainsString("->where('is_available', true)", $service);
        $this->assertStringContainsString('$productModels->get($cartItem[\'id\'])', $service);
        $this->assertStringContainsString('Product {$cartItem[\'id\']} is unavailable at the selected branch.', $service);
        $this->assertStringNotContainsString('$productModels[$cartItem[\'id\']]', $service);
        $this->assertStringContainsString('$this->replaceOrderTaxes($order, $taxes)', $service);
        $this->assertStringNotContainsString('$order->storeTaxes($taxes)', $service);
        $this->assertStringContainsString('"due_amount" => $hasPayments ? 0 : $data[\'total\']', $service);
        $this->assertStringContainsString("setting('partner_api_delivery_radius_policy', 'enforce') === 'bypass'", $controller);
        $this->assertStringContainsString("'pricing_rule' => 'partner_api_radius_bypass'", $controller);
    }
}
