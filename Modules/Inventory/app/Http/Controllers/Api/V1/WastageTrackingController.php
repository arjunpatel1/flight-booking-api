<?php

namespace Modules\Inventory\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Support\InputLimit;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\Inventory\Services\WastageTracking\WastageTrackingServiceInterface;

class WastageTrackingController extends Controller
{
    public function __construct(
        private readonly WastageTrackingServiceInterface $wastageService
    ) {
    }

    /**
     * Get available wastage reasons.
     */
    public function getReasons(): JsonResponse
    {
        return ApiResponse::success($this->wastageService->getWastageReasons());
    }

    /**
     * Record wastage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ingredient_id' => 'required|integer|exists:ingredients,id',
            // Fractional is legitimate here (0.25 kg of waste), but bounded.
            'quantity' => ['required', ...InputLimit::quantity(min: 0.01)],
            'reason' => 'required|string|max:255',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'note' => 'nullable|string|max:500',
        ]);

        $branchId = auth()->user()?->assignedToBranch()
            ? auth()->user()->branch_id
            : $validated['branch_id'] ?? null;

        if (is_null($branchId)) {
            return ApiResponse::errors(
                errors: ['branch_id' => [__('validation.required', ['attribute' => __('branch::branches.branch')])]],
                code: 422
            );
        }

        $result = $this->wastageService->recordWastage(
            ingredientId: $validated['ingredient_id'],
            quantity: $validated['quantity'],
            reason: $validated['reason'],
            branchId: $branchId,
            reportedBy: auth()->user()?->id,
            note: $validated['note'] ?? null
        );

        if (!$result['success']) {
            return ApiResponse::errors(null, $result['message']);
        }

        return ApiResponse::created($result, __('inventory::stock_movements.stock_movement'), __('inventory::messages.wastage_recorded'));
    }

    /**
     * Get wastage report.
     */
    public function getReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'ingredient_id' => 'nullable|integer|exists:ingredients,id',
        ]);

        $report = $this->wastageService->getWastageReport(
            from: $validated['from'],
            to: $validated['to'],
            branchId: $this->resolveBranchId($validated['branch_id'] ?? null),
            ingredientId: $validated['ingredient_id'] ?? null
        );

        return ApiResponse::success($report);
    }

    /**
     * Get top wasted ingredients.
     */
    public function getTopWasted(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'limit' => 'nullable|integer|min:1|max:50',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $topWasted = $this->wastageService->getTopWastedIngredients(
            from: $validated['from'],
            to: $validated['to'],
            limit: $validated['limit'] ?? 10,
            branchId: $this->resolveBranchId($validated['branch_id'] ?? null)
        );

        return ApiResponse::success($topWasted);
    }

    /**
     * Get wastage by reason.
     */
    public function getByReason(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $byReason = $this->wastageService->getWastageByReason(
            from: $validated['from'],
            to: $validated['to'],
            branchId: $this->resolveBranchId($validated['branch_id'] ?? null)
        );

        return ApiResponse::success($byReason);
    }

    /**
     * Get wastage trends.
     */
    public function getTrends(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'group_by' => 'nullable|in:day,week,month',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $trends = $this->wastageService->getWastageTrends(
            from: $validated['from'],
            to: $validated['to'],
            groupBy: $validated['group_by'] ?? 'day',
            branchId: $this->resolveBranchId($validated['branch_id'] ?? null)
        );

        return ApiResponse::success($trends);
    }

    private function resolveBranchId(?int $branchId): ?int
    {
        $user = auth()->user();

        if ($user?->assignedToBranch()) {
            return $user->branch_id;
        }

        return $branchId;
    }
}
