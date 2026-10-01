<?php

namespace Modules\Dashboard\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Dashboard\Enums\AnalyticsPeriod;
use Modules\Dashboard\Enums\SalesAnalyticsFilter;
use Modules\Dashboard\Services\Dashboard\DashboardServiceInterface;
use Modules\Dashboard\Jobs\GenerateAiInsightSnapshot;
use Modules\Dashboard\Transformers\Api\V1\AiInsightSnapshotResource;
use Modules\Support\ApiResponse;

class DashboardController extends Controller
{
    /**
     * Create a new instance of DashboardController
     *
     * @param DashboardServiceInterface $service
     */
    public function __construct(protected DashboardServiceInterface $service)
    {
    }

    /**
     * Get dashboard overview
     *
     * @return JsonResponse
     */
    public function overview(Request $request): JsonResponse
    {
        $period = AnalyticsPeriod::tryFrom((string) $request->query('period')) ?? AnalyticsPeriod::Today;

        return ApiResponse::success($this->service->overview($period));
    }

    /**
     * Get dashboard operational metrics
     *
     * @return JsonResponse
     */
    public function operationalMetrics(): JsonResponse
    {
        return ApiResponse::success($this->service->operationalMetrics());
    }

    public function monitoring(): JsonResponse
    {
        return ApiResponse::success($this->service->monitoring());
    }

    public function smartInsights(): JsonResponse
    {
        return ApiResponse::success($this->service->smartInsights());
    }

    public function demandForecast(): JsonResponse
    {
        return ApiResponse::success($this->service->demandForecast());
    }

    public function smartInsightSnapshots(): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->smartInsightSnapshots(),
            resource: AiInsightSnapshotResource::class,
        );
    }

    public function generateSmartInsightSnapshot(): JsonResponse
    {
        GenerateAiInsightSnapshot::dispatch(auth()->id());

        return ApiResponse::success([
            'queued' => true,
        ]);
    }

    public function assistant(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string'],
        ]);

        return ApiResponse::success($this->service->assistant($validated['message']));
    }

    /**
     * Get dashboard sales analytics
     *
     * @param SalesAnalyticsFilter $filter
     * @return JsonResponse
     */
    public function salesAnalytics(SalesAnalyticsFilter $filter): JsonResponse
    {
        return ApiResponse::success($this->service->salesAnalytics($filter));
    }

    /**
     * Get dashboard best performing branches
     *
     * @param AnalyticsPeriod $filter
     * @return JsonResponse
     */
    public function bestPerformingBranches(AnalyticsPeriod $filter): JsonResponse
    {
        return ApiResponse::success($this->service->bestPerformingBranches($filter));
    }

    /**
     * Get dashboard order type distribution
     *
     * @param AnalyticsPeriod $filter
     * @return JsonResponse
     */
    public function orderTypeDistribution(AnalyticsPeriod $filter): JsonResponse
    {
        return ApiResponse::success($this->service->orderTypeDistribution($filter));
    }

    /**
     * Get dashboard order total by status
     *
     * @param AnalyticsPeriod $filter
     * @return JsonResponse
     */
    public function orderTotalByStatus(AnalyticsPeriod $filter): JsonResponse
    {
        return ApiResponse::success($this->service->orderTotalByStatus($filter));
    }

    /**
     * Get dashboard payments overview
     *
     * @param AnalyticsPeriod $filter
     * @return JsonResponse
     */
    public function paymentsOverview(AnalyticsPeriod $filter): JsonResponse
    {
        return ApiResponse::success($this->service->paymentsOverview($filter));
    }

    /**
     * Get dashboard hourly sales trend
     *
     * @return JsonResponse
     */
    public function hourlySalesTrend(): JsonResponse
    {
        return ApiResponse::success($this->service->hourlySalesTrend());
    }

    /**
     * Get dashboard branch wise sales comparison
     *
     * @param AnalyticsPeriod $filter
     * @return JsonResponse
     */
    public function branchWiseSalesComparison(AnalyticsPeriod $filter): JsonResponse
    {
        return ApiResponse::success($this->service->branchWiseSalesComparison($filter));
    }

    /**
     * Get dashboard cash movements Overview
     *
     * @param AnalyticsPeriod $filter
     * @return JsonResponse
     */
    public function cashMovementsOverview(AnalyticsPeriod $filter): JsonResponse
    {
        return ApiResponse::success($this->service->cashMovementsOverview($filter));
    }

    /**
     * Get dashboard top selling products
     *
     * @param AnalyticsPeriod $filter
     * @return JsonResponse
     */
    public function topSellingProducts(AnalyticsPeriod $filter): JsonResponse
    {
        return ApiResponse::success($this->service->topSellingProducts($filter));
    }

    /**
     * Get low stock alerts
     *
     * @return JsonResponse
     */
    public function getLowStockAlerts(): JsonResponse
    {
        return ApiResponse::success($this->service->getLowStockAlerts());
    }

    /**
     * Get system health status.
     *
     * @return JsonResponse
     */
    public function systemHealth(): JsonResponse
    {
        return ApiResponse::success($this->service->systemHealth());
    }

    /**
     * Global search across products, customers, and orders.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function globalSearch(Request $request): JsonResponse
    {
        $query = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return ApiResponse::success(
            $this->service->globalSearch($query['q'], $query['limit'] ?? 10)
        );
    }

    /**
     * Get command palette quick actions.
     *
     * @return JsonResponse
     */
    public function commandPalette(): JsonResponse
    {
        return ApiResponse::success($this->service->commandPalette());
    }

}
