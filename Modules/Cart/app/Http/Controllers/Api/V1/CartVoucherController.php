<?php

namespace Modules\Cart\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cart\Facades\Cart;
use Modules\Cart\Services\DiscountApplyService\DiscountApplyServiceInterface;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;

class CartVoucherController extends Controller
{
    /**
     * Apply voucher to cart
     *
     * @param Request $request
     * @param DiscountApplyServiceInterface $service
     * @return JsonResponse
     */
    public function store(Request $request, DiscountApplyServiceInterface $service): JsonResponse
    {
        $service->applyVoucher(Cart::class, $request->input('code', ''));

        return ApiResponse::success(Cart::instance());
    }
}
