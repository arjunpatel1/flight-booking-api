<?php

namespace Modules\Report\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Report\Services\Shift\ShiftAnalyticsService;
use Modules\Support\Http\Controllers\ApiController;

class ShiftAnalyticsController extends ApiController
{
    protected ShiftAnalyticsService $shiftAnalyticsService;

    public function __construct(ShiftAnalyticsService $shiftAnalyticsService)
    {
        $this->shiftAnalyticsService = $shiftAnalyticsService;
    }

    public function getSummary(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->shiftAnalyticsService->getSummary($branchId, $startDate, $endDate);

        return $this->responseSuccess($data);
    }

    public function getByShift(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->shiftAnalyticsService->getByShift($branchId, $startDate, $endDate);

        return $this->responseSuccess($data);
    }

    public function getByUser(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->shiftAnalyticsService->getByUser($branchId, $startDate, $endDate);

        return $this->responseSuccess($data);
    }

    public function getDailyTrend(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->shiftAnalyticsService->getDailyTrend($branchId, $startDate, $endDate);

        return $this->responseSuccess($data);
    }

    public function getCashReconciliation(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->shiftAnalyticsService->getCashReconciliation($branchId, $startDate, $endDate);

        return $this->responseSuccess($data);
    }
}
