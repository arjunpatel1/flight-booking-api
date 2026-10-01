<?php

namespace Modules\FinancialDashboard\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Branch\Models\Branch;
use Modules\FinancialDashboard\Services\FinancialDashboardService;
use Modules\Support\ApiResponse;

class FinancialDashboardController extends Controller
{
    public function __construct(protected FinancialDashboardService $service) {}

    public function kpis(Request $request): JsonResponse
    {
        ['start_date' => $start, 'end_date' => $end, 'branch_id' => $branchId] = $this->validatedRange($request);

        return ApiResponse::success($this->service->getKpis($start, $end, $branchId));
    }

    public function salesTrend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
            'branch_id'  => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
            'group_by'   => ['sometimes', 'nullable', Rule::in(['date', 'week', 'month', 'year'])],
        ]);

        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? null);

        return ApiResponse::success(
            $this->service->getSalesTrend($data['start_date'], $data['end_date'], $branchId, $data['group_by'] ?? 'date')
        );
    }

    public function branchAnalytics(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        return ApiResponse::success($this->service->getBranchAnalytics($data['start_date'], $data['end_date']));
    }

    public function profitability(Request $request): JsonResponse
    {
        ['start_date' => $start, 'end_date' => $end, 'branch_id' => $branchId] = $this->validatedRange($request);

        return ApiResponse::success($this->service->getProfitability($start, $end, $branchId));
    }

    public function categoryAnalytics(Request $request): JsonResponse
    {
        ['start_date' => $start, 'end_date' => $end, 'branch_id' => $branchId] = $this->validatedRange($request);

        return ApiResponse::success($this->service->getCategoryAnalytics($start, $end, $branchId));
    }

    public function topItems(Request $request): JsonResponse
    {
        ['start_date' => $start, 'end_date' => $end, 'branch_id' => $branchId] = $this->validatedRange($request);

        return ApiResponse::success($this->service->getTopItems($start, $end, $branchId));
    }

    public function menuEngineering(Request $request): JsonResponse
    {
        ['start_date' => $start, 'end_date' => $end, 'branch_id' => $branchId] = $this->validatedRange($request);

        return ApiResponse::success($this->service->getMenuEngineering($start, $end, $branchId));
    }

    public function paymentAnalytics(Request $request): JsonResponse
    {
        ['start_date' => $start, 'end_date' => $end, 'branch_id' => $branchId] = $this->validatedRange($request);

        return ApiResponse::success($this->service->getPaymentAnalytics($start, $end, $branchId));
    }

    public function customerAnalytics(Request $request): JsonResponse
    {
        ['start_date' => $start, 'end_date' => $end, 'branch_id' => $branchId] = $this->validatedRange($request);

        return ApiResponse::success($this->service->getCustomerAnalytics($start, $end, $branchId));
    }

    public function peakHours(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['sometimes', 'date'],
            'end_date'   => ['sometimes', 'date', 'after_or_equal:start_date'],
            'branch_id'  => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
        ]);
        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? null);
        $start    = $data['start_date'] ?? today()->startOfMonth()->toDateString();
        $end      = $data['end_date']   ?? today()->toDateString();

        return ApiResponse::success($this->service->getPeakHours($start, $end, $branchId));
    }

    public function peakDays(Request $request): JsonResponse
    {
        ['start_date' => $start, 'end_date' => $end, 'branch_id' => $branchId] = $this->validatedRange($request);

        return ApiResponse::success($this->service->getPeakDays($start, $end, $branchId));
    }

    private function validatedRange(Request $request): array
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
            'branch_id'  => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
        ]);

        $data['branch_id'] = $this->resolvedBranchId($request, $data['branch_id'] ?? null);

        return $data;
    }

    private function resolvedBranchId(Request $request, ?int $branchId = null): ?int
    {
        $actor = $request->user();

        if ($actor->assignedToBranch()) {
            return $actor->branch_id;
        }

        $resolved = $branchId ?? ($request->integer('branch_id') ?: null);
        if ($resolved && $actor->assignedToTenant() && ! $actor->isSuperAdmin()) {
            abort_unless(
                Branch::query()->whereKey($resolved)->where('tenant_id', $actor->tenantId())->exists(),
                403,
                'Branch is not available for this restaurant.'
            );
        }

        return $resolved;
    }
}
