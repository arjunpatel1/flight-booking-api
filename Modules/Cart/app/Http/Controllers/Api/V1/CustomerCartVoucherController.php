<?php

namespace Modules\Cart\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cart\Facades\Cart;
use Modules\Cart\Services\DiscountApplyService\DiscountApplyServiceInterface;
use Modules\Cart\Support\PublicTenantGuard;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer voucher boundary.
 *
 * The app submits only a code. Tenant, customer, branch, eligible products,
 * taxes, discount and final total are all resolved from the authenticated
 * customer-app context and the server-owned cart.
 */
class CustomerCartVoucherController extends Controller
{
    public function store(
        Request $request,
        string $cartId,
        DiscountApplyServiceInterface $service,
    ): JsonResponse {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80'],
        ]);

        $this->guardCart($request);
        Cart::addCustomer($request->user());
        $service->applyVoucher(Cart::class, trim($data['code']));
        Cart::addTaxes();

        return ApiResponse::success(Cart::instance());
    }

    public function destroy(Request $request, string $cartId): JsonResponse
    {
        $this->guardCart($request);
        Cart::removeDiscount();
        Cart::addTaxes();

        return ApiResponse::success(Cart::instance());
    }

    private function guardCart(Request $request): void
    {
        $customer = $request->user();
        abort_unless(
            $customer
            && $customer->hasRole(DefaultRole::Customer->value)
            && (int) $customer->tenant_id === PublicTenantGuard::tenantId($request),
            Response::HTTP_FORBIDDEN,
            __('auth.failed'),
        );

        PublicTenantGuard::assertBranch($request, Cart::branch()?->id());
        abort_if(Cart::items()->isEmpty(), Response::HTTP_UNPROCESSABLE_ENTITY, 'Add an item before applying a coupon.');
        PublicTenantGuard::assertCartProducts($request, Cart::branch()?->id(), Cart::items());
    }

}
