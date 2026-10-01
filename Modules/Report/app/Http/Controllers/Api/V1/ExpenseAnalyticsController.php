<?php

namespace Modules\Report\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Report\Services\Expense\ExpenseAnalyticsService;
use Modules\Support\Http\Controllers\ApiController;

class ExpenseAnalyticsController extends ApiController
{
    protected ExpenseAnalyticsService $expenseAnalyticsService;

    public function __construct(ExpenseAnalyticsService $expenseAnalyticsService)
    {
        $this->expenseAnalyticsService = $expenseAnalyticsService;
    }

    public function getSummary(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->expenseAnalyticsService->getSummary($branchId, $startDate, $endDate);

        return $this->responseSuccess($data);
    }

    public function getByCategory(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->expenseAnalyticsService->getByCategory($branchId, $startDate, $endDate);

        return $this->responseSuccess($data);
    }

    public function getDailyTrend(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->expenseAnalyticsService->getDailyTrend($branchId, $startDate, $endDate);

        return $this->responseSuccess($data);
    }

    public function getOutletComparison(Request $request): JsonResponse
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->expenseAnalyticsService->getOutletComparison($startDate, $endDate);

        return $this->responseSuccess($data);
    }

    public function getCategoryWiseExpense(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->expenseAnalyticsService->getCategoryWiseExpense($branchId, $startDate, $endDate);

        return $this->responseSuccess($data);
    }
}
