<?php

namespace Modules\Report\Http\Controllers\Api\V1;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Controllers\Controller;
use Modules\Report\Services\Analytics\AnalyticsService;
use Modules\Support\ApiResponse;

class AnalyticsController extends Controller
{
    public function __construct(
        protected AnalyticsService $analyticsService
    ) {
    }

    public function realtime(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? null);

        return ApiResponse::success(
            $this->analyticsService->getRealtimeDashboard($branchId)
        );
    }

    public function sales(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
            'group_by' => ['sometimes', 'nullable', Rule::in(['date', 'branch'])],
        ]);

        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? null);

        return ApiResponse::success(
            $this->analyticsService->getSalesAnalytics(
                $data['start_date'],
                $data['end_date'],
                $branchId,
                $data['group_by'] ?? null
            )
        );
    }

    public function profit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
            'group_by' => ['sometimes', 'nullable', Rule::in(['date', 'branch'])],
        ]);

        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? null);

        return ApiResponse::success(
            $this->analyticsService->getSalesAnalytics(
                $data['start_date'],
                $data['end_date'],
                $branchId,
                $data['group_by'] ?? null
            )
        );
    }

    public function outlets(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        return ApiResponse::success(
            $this->analyticsService->getOutletComparison(
                $data['start_date'],
                $data['end_date']
            )
        );
    }

    public function peakHours(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
            'product_id' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
        ]);

        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? null);

        return ApiResponse::success(
            $this->analyticsService->getPeakHours(
                $data['start_date'],
                $data['end_date'],
                $branchId,
                $data['product_id'] ?? null
            )
        );
    }

    public function menuEngineering(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? null);

        return ApiResponse::success(
            $this->analyticsService->getMenuEngineeringMetrics(
                $data['start_date'],
                $data['end_date'],
                $branchId
            )
        );
    }

    public function inventory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? null);

        return ApiResponse::success(
            $this->analyticsService->getInventoryAnalytics(
                $data['start_date'],
                $data['end_date'],
                $branchId
            )
        );
    }

    public function deadItems(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->resolvedBranchId($request, $data['branch_id'] ?? null);

        $metrics = $this->analyticsService->getMenuEngineeringMetrics(
            $data['start_date'],
            $data['end_date'],
            $branchId
        );

        // Filter for "dog" classification (low sales, low quantity)
        $deadItems = $metrics->filter(fn($item) => $item['classification'] === 'dog');

        return ApiResponse::success([
            'period' => [
                'start' => $data['start_date'],
                'end' => $data['end_date'],
            ],
            'dead_items' => $deadItems->values(),
            'total_dead_items' => $deadItems->count(),
        ]);
    }

    private function resolvedBranchId(Request $request, ?int $branchId = null): ?int
    {
        $user = auth()->user();

        if ($user->assignedToBranch()) {
            return $user->branch_id;
        }

        $requested = $branchId ?? ($request->integer('branch_id') ?: null);

        // A tenant-scoped admin may only target a branch inside its own tenant.
        // A foreign branch_id is dropped to null so analytics fall back to the
        // caller's own tenant scope instead of reading another tenant's data.
        if ($requested !== null && $user->assignedToTenant() && ! $user->isSuperAdmin()) {
            $ownsBranch = Branch::query()
                ->withOutGlobalScopes()
                ->whereKey($requested)
                ->where('tenant_id', $user->tenant_id)
                ->exists();

            return $ownsBranch ? $requested : null;
        }

        return $requested;
    }
}
