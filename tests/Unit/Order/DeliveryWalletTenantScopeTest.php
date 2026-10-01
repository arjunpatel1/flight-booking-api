<?php

namespace Tests\Unit\Order;

use Illuminate\Http\Request;
use Modules\Order\Http\Controllers\Api\V1\DeliveryWalletController;
use Modules\Saas\Support\TenantContext;
use Modules\User\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class DeliveryWalletTenantScopeTest extends TestCase
{
    public function test_tenant_actor_cannot_select_another_domain_wallet(): void
    {
        $request = Request::create('/api/v1/settings/delivery/wallet');
        $request->setUserResolver(fn () => new User(['tenant_id' => 10]));
        $context = new TenantContext;
        $context->setId(20);

        $this->expectException(NotFoundHttpException::class);
        (new \ReflectionMethod(DeliveryWalletController::class, 'restaurantTenantId'))
            ->invoke(new DeliveryWalletController, $request, $context);
    }

    public function test_tenant_actor_can_read_own_wallet_context(): void
    {
        $request = Request::create('/api/v1/settings/delivery/wallet');
        $request->setUserResolver(fn () => new User(['tenant_id' => 10]));
        $context = new TenantContext;
        $context->setId(10);

        $tenantId = (new \ReflectionMethod(DeliveryWalletController::class, 'restaurantTenantId'))
            ->invoke(new DeliveryWalletController, $request, $context);

        $this->assertSame(10, $tenantId);
    }
}
