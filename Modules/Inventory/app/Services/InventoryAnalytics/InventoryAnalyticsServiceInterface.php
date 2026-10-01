<?php

namespace Modules\Inventory\Services\InventoryAnalytics;

interface InventoryAnalyticsServiceInterface
{
    /**
     * Get filters data
     *
     * @return array
     */
    public function getMetaData(): array;

    /**
     * Get top suppliers based on total purchase amount.
     *
     * @param string|null $from
     * @param string|null $to
     * @param int|null $branchId
     * @return array
     */
    public function topSuppliers(?string $from, ?string $to, ?int $branchId): array;

    /**
     * Get total ingredient purchases grouped by ingredient.
     *
     * @param string|null $from
     * @param string|null $to
     * @param int|null $branchId
     * @return array
     */
    public function ingredientPurchases(?string $from, ?string $to, ?int $branchId): array;

    /**
     * Get stock movement summary grouped by type.
     *
     * @param string|null $from
     * @param string|null $to
     * @param int|null $branchId
     * @return array
     */
    public function stockMovementSummary(?string $from, ?string $to, ?int $branchId): array;

    /**
     * Get total wastage and spoilage quantities.
     *
     * @param string|null $from
     * @param string|null $to
     * @param int|null $branchId
     * @return array
     */
    public function wastageAndSpoilage(?string $from, ?string $to, ?int $branchId): array;

    /**
     * Get count of purchases grouped by status.
     *
     * @param string|null $from
     * @param string|null $to
     * @param int|null $branchId
     * @return array
     */
    public function purchaseStatusSummary(?string $from, ?string $to, ?int $branchId): array;

    /**
     * Get a list of low stock ingredients for the analytics dashboard.
     *
     * This method retrieves ingredients where the current stock quantity is less than or equal to the
     * defined low stock threshold.
     * It optionally filters by branch if a branch ID is provided.
     *
     * @param int|null $branchId The branch ID to filter ingredients by (optional).
     *
     * @return array
     */
    public function lowStockIngredients(?int $branchId = null): array;

    /**
     * Get current stock valuation grouped by ingredient.
     *
     * @param int|null $branchId
     * @return array
     */
    public function stockValuation(?int $branchId = null): array;

    /**
     * Get reorder suggestions based on stock level and recent consumption.
     *
     * @param int|null $branchId
     * @param int $lookbackDays
     * @param int $targetDays
     * @return array
     */
    public function reorderSuggestions(?int $branchId = null, int $lookbackDays = 30, int $targetDays = 7): array;

    /**
     * Get a list of fast-moving ingredients for the analytics dashboard.
     *
     * This method identifies ingredients with the highest consumption or usage volume
     * over a recent period (e.g., last 30 days). Optionally filtered by branch.
     *
     * @param string|null $from Start date for filtering (Y-m-d format).
     * @param string|null $to End date for filtering (Y-m-d format).
     * @param int|null $branchId Branch ID to filter the results (optional).
     *
     * @return array
     */
    public function fastMovingIngredients(?string $from = null, ?string $to = null, ?int $branchId = null): array;

    /**
     * Get the top 5 most wasted or spoiled ingredients.
     *
     * @param string|null $from
     * @param string|null $to
     * @param int|null $branchId
     * @return array
     */
    public function mostWastedIngredients(?string $from = null, ?string $to = null, ?int $branchId = null): array;

}
