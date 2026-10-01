<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Pos\Services\PosViewer\PosViewerServiceInterface;
use Modules\Support\ApiResponse;

class PosViewerController extends Controller
{
    /**
     * Create a new instance of PosController
     *
     * @param PosViewerServiceInterface $service
     */
    public function __construct(protected PosViewerServiceInterface $service)
    {
    }

    /**
     * Get pos configuration
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function configuration(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->getConfiguration(
                $request->integer('branch_id') ?: null,
                $request->boolean('lightweight')
            )
        );
    }

    /**
     * Get menu items
     *
     * @param string $cartId
     * @param int $menuId
     * @return JsonResponse
     */
    public function defaultMenuItems(Request $request, string $cartId): JsonResponse
    {
        $configuration = $this->service->getConfiguration(
            $request->integer('branch_id') ?: null,
            true
        );
        $menuId = (int) ($configuration['menu_id'] ?? 0);

        abort_if($menuId <= 0, 422, __('pos::pos.menu_required'));

        return ApiResponse::success($this->service->getMenuItems(
            menuId: $menuId,
            orderType: $request->input('order_type') ?: null,
            tableId: $request->integer('table_id') ?: null,
            zoneId: $request->integer('zone_id') ?: null,
        ));
    }

    public function menuItems(Request $request, string $cartId, int $menuId): JsonResponse
    {
        return ApiResponse::success($this->service->getMenuItems(
            menuId: $menuId,
            orderType: $request->input('order_type') ?: null,
            tableId: $request->integer('table_id') ?: null,
            zoneId: $request->integer('zone_id') ?: null,
        ));
    }

    public function menuProduct(Request $request, string $cartId, int $menuId, int $productId): JsonResponse
    {
        return ApiResponse::success($this->service->getMenuProduct(
            menuId: $menuId,
            productId: $productId,
            orderType: $request->input('order_type') ?: null,
            tableId: $request->integer('table_id') ?: null,
            zoneId: $request->integer('zone_id') ?: null,
        ));
    }

    public function waiterDashboard(Request $request, string $cartId): JsonResponse
    {
        return ApiResponse::success($this->service->waiterDashboard($request->integer('branch_id') ?: null));
    }

    public function waiterDashboardOverview(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->waiterDashboard($request->integer('branch_id') ?: null));
    }

    public function waiterAssistant(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->waiterAssistant($request->integer('branch_id') ?: null));
    }

    public function performanceIntelligence(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->performanceIntelligence(
            $request->integer('branch_id') ?: null,
            $request->input('period', 'today')
        ));
    }

    public function revenueIntelligence(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->revenueIntelligence(
            $request->integer('branch_id') ?: null,
            $request->input('period', 'today')
        ));
    }
}
