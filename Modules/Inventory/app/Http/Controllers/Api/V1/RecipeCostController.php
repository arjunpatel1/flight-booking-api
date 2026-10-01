<?php

namespace Modules\Inventory\Http\Controllers\Api\V1;

use App\NexDine;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Controllers\Controller;
use Modules\Inventory\Services\RecipeCost\RecipeCostCalculatorInterface;
use Modules\Menu\Models\Menu;
use Modules\Product\Models\Product;
use Modules\Support\ApiResponse;

class RecipeCostController extends Controller
{
    public function __construct(
        private readonly RecipeCostCalculatorInterface $costCalculator
    ) {
    }

    /**
     * Get cost analysis for a specific product.
     */
    public function getProductCost(int $productId): JsonResponse
    {
        $product = Product::query()
            ->with('ingredients.ingredient.unit')
            ->findOrFail($productId);

        return ApiResponse::success([
                'cost' => $this->costCalculator->calculateProductCost($product),
                'margin' => $this->costCalculator->calculateFoodMargin($product),
        ]);
    }

    /**
     * Get recommended price for a product.
     */
    public function getRecommendedPrice(Request $request, int $productId): JsonResponse
    {
        $request->validate([
            'target_food_cost_pct' => 'nullable|numeric|min:1|max:100',
        ]);

        $product = Product::findOrFail($productId);
        $targetPercentage = $request->input('target_food_cost_pct', 30.0);

        return ApiResponse::success($this->costCalculator->getRecommendedPrice($product, $targetPercentage));
    }

    /**
     * Get menu cost analysis.
     */
    public function getMenuAnalysis(Request $request): JsonResponse
    {
        $request->validate([
            'menu_id' => 'nullable|integer|exists:menus,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'filters' => 'nullable|array',
            'filters.search' => 'nullable|string|max:120',
            'filters.menu_id' => 'nullable|integer|exists:menus,id',
            'filters.branch_id' => 'nullable|integer|exists:branches,id',
            'filters.status' => 'nullable|in:healthy,warning,critical',
            'status' => 'nullable|in:healthy,warning,critical',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $filters = $request->input('filters', []);
        $menuId = $filters['menu_id'] ?? $request->input('menu_id');
        $branchId = $this->resolveBranchId($filters['branch_id'] ?? $request->input('branch_id'));
        $analysis = $this->costCalculator->getMenuCostAnalysis(
            $menuId,
            $branchId
        );
        $search = $filters['search'] ?? null;
        $status = $filters['status'] ?? $request->input('status');

        $analysis = $analysis
            ->when($search, fn($items) => $items->filter(
                fn($item) => str_contains(strtolower($item['name']), strtolower($search))
                    || str_contains(strtolower($item['sku'] ?? ''), strtolower($search))
                    || str_contains(strtolower($item['category'] ?? ''), strtolower($search))
            ))
            ->when($status, fn($items) => $items->where('status', $status))
            ->values();

        return ApiResponse::pagination($this->paginateCollection($analysis, $request));
    }

    public function getFormMeta(): JsonResponse
    {
        $branchId = $this->resolveBranchId(null);

        return ApiResponse::success([
            'branches' => auth()->user()?->assignedToBranch() ? [] : Branch::list(),
            'menus' => Menu::list($branchId),
        ]);
    }

    /**
     * Get menu analysis stats with translated labels.
     */
    public function getMenuStats(Request $request): JsonResponse
    {
        $request->validate([
            'menu_id' => 'nullable|integer|exists:menus,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $filters = $request->input('filters', []);
        $menuId = $filters['menu_id'] ?? $request->input('menu_id');
        $branchId = $this->resolveBranchId($filters['branch_id'] ?? $request->input('branch_id'));

        $analysis = $this->costCalculator->getMenuCostAnalysis($menuId, $branchId);

        $total = $analysis->count();
        $healthy = $analysis->where('status', 'healthy')->count();
        $warning = $analysis->where('status', 'warning')->count();
        $critical = $analysis->where('status', 'critical')->count();
        $avgFoodCost = $total > 0
            ? round($analysis->avg('food_cost_pct'), 2)
            : 0;

        return ApiResponse::success([
            'total_products' => [
                'value' => $total,
                'label' => trans('inventory::recipe_costing.stats.total_products'),
            ],
            'healthy_count' => [
                'value' => $healthy,
                'label' => trans('inventory::recipe_costing.stats.healthy'),
            ],
            'warning_count' => [
                'value' => $warning,
                'label' => trans('inventory::recipe_costing.stats.warning'),
            ],
            'critical_count' => [
                'value' => $critical,
                'label' => trans('inventory::recipe_costing.stats.critical'),
            ],
            'avg_food_cost_pct' => [
                'value' => $avgFoodCost,
                'label' => trans('inventory::recipe_costing.stats.avg_food_cost'),
            ],
        ]);
    }

    /**
     * Get high cost products.
     */
    public function getHighCostProducts(Request $request): JsonResponse
    {
        $request->validate([
            'threshold' => 'nullable|numeric|min:1|max:100',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $products = $this->costCalculator->getHighCostProducts(
            $request->input('threshold', 35.0),
            $this->resolveBranchId($request->input('branch_id'))
        );

        $withMargins = $products->map(fn($p) => [
            'product' => $p,
            'margin' => $this->costCalculator->calculateFoodMargin($p),
        ]);

        return ApiResponse::success($withMargins);
    }

    private function resolveBranchId(?int $branchId): ?int
    {
        $user = auth()->user();

        if ($user?->assignedToBranch()) {
            return $user->branch_id;
        }

        return $branchId;
    }

    private function paginateCollection($items, Request $request): LengthAwarePaginator
    {
        $perPage = (int) $request->input('per_page', NexDine::paginate());
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            items: $items->forPage($page, $perPage)->values(),
            total: $items->count(),
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }
}
