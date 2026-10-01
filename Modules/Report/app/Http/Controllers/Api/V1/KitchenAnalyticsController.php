<?php

namespace Modules\Report\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Report\Services\Kitchen\KitchenAnalyticsService;
use Modules\Support\Http\Controllers\ApiController;

class KitchenAnalyticsController extends ApiController
{
    protected KitchenAnalyticsService $kitchenAnalyticsService;

    public function __construct(KitchenAnalyticsService $kitchenAnalyticsService)
    {
        $this->kitchenAnalyticsService = $kitchenAnalyticsService;
    }

    public function getSummary(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id');
        $stationId = $request->input('station_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->kitchenAnalyticsService->getSummary($branchId, $stationId, $startDate, $endDate);

        return $this->responseSuccess($data);
    }

    public function getByStation(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->kitchenAnalyticsService->getByStation($branchId, $startDate, $endDate);

        return $this->responseSuccess($data);
    }

    public function getDailyTrend(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id');
        $stationId = $request->input('station_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->kitchenAnalyticsService->getDailyTrend($branchId, $stationId, $startDate, $endDate);

        return $this->responseSuccess($data);
    }

    public function getPeakHours(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id');
        $stationId = $request->input('station_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->kitchenAnalyticsService->getPeakHours($branchId, $stationId, $startDate, $endDate);

        return $this->responseSuccess($data);
    }
}
