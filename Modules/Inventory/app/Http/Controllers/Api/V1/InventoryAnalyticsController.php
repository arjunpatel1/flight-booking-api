<?php

namespace Modules\Inventory\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Inventory\Services\InventoryAnalytics\InventoryAnalyticsServiceInterface;
use Modules\Support\ApiResponse;

class InventoryAnalyticsController extends Controller
{
    /**
     * Create a new instance of InventoryAnalyticsController
     *
     * @param InventoryAnalyticsServiceInterface $service
     */
    public function __construct(protected InventoryAnalyticsServiceInterface $service)
    {
    }

    /**
     * Get meta Data
     *
     * @return JsonResponse
     */
    public function getMetaData(): JsonResponse
    {
        return ApiResponse::success($this->service->getMetaData());
    }

    /**
     * Top suppliers report
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function topSuppliers(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->topSuppliers(
                $request->input('from'),
                $request->input('to'),
                $request->input('branch_id')
            )
        );
    }

    /**
     * Ingredient purchases summary
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function ingredientPurchases(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->ingredientPurchases(
                $request->input('from'),
                $request->input('to'),
                $request->input('branch_id')
            )
        );
    }

    /**
     * Stock movement overview
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function stockMovementSummary(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->stockMovementSummary(
                $request->input('from'),
                $request->input('to'),
                $request->input('branch_id')
            )
        );
    }

    /**
     * Wastage and spoilage analysis
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function wastageAndSpoilage(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->wastageAndSpoilage(
                $request->input('from'),
                $request->input('to'),
                $request->input('branch_id')
            )
        );
    }

    /**
     * Purchase status summary
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function purchaseStatusSummary(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->purchaseStatusSummary(
                $request->input('from'),
                $request->input('to'),
                $request->input('branch_id')
            )
        );
    }

    /**
     * Get a list of fast-moving ingredients for the analytics dashboard.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function fastMovingIngredients(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->fastMovingIngredients(
                $request->input('from'),
                $request->input('to'),
                $request->input('branch_id')
            )
        );
    }

    /**
     * Get the top 5 most wasted or spoiled ingredients.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function mostWastedIngredients(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->mostWastedIngredients(
                $request->input('from'),
                $request->input('to'),
                $request->input('branch_id')
            )
        );
    }

    /**
     * et a list of low stock ingredients for the analytics dashboard.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function lowStockIngredients(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->lowStockIngredients(
                $request->input('branch_id')
            )
        );
    }

    /**
     * Current stock valuation report
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function stockValuation(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->stockValuation(
                $request->input('branch_id')
            )
        );
    }

    /**
     * Suggested reorder list based on low stock and recent usage.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function reorderSuggestions(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->reorderSuggestions(
                branchId: $request->input('branch_id'),
                lookbackDays: $request->integer('lookback_days', 30),
                targetDays: $request->integer('target_days', 7),
            )
        );
    }

}
