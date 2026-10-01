<?php

namespace Modules\Inventory\Services\VendorPurchase;

use Illuminate\Support\Collection;
use Modules\Inventory\Enums\PurchaseStatus;

interface VendorPurchaseServiceInterface
{
    /**
     * Create a new purchase order.
     *
     * @param array $data
     * @return array{success: bool, purchase?: \Modules\Inventory\Models\Purchase, error?: string}
     */
    public function createPurchase(array $data): array;

    /**
     * Update purchase order status.
     *
     * @param int $purchaseId
     * @param PurchaseStatus $status
     * @param string|null $notes
     * @return array{success: bool, purchase?: \Modules\Inventory\Models\Purchase, error?: string}
     */
    public function updatePurchaseStatus(int $purchaseId, PurchaseStatus $status, ?string $notes = null): array;

    /**
     * Receive purchase order items and update stock.
     *
     * @param int $purchaseId
     * @param array $items
     * @return array{success: bool, error?: string}
     */
    public function receivePurchase(int $purchaseId, array $items): array;

    /**
     * Get purchase orders for a branch.
     *
     * @param int|null $branchId
     * @param array $filters
     * @return Collection
     */
    public function getPurchaseOrders(?int $branchId = null, array $filters = []): Collection;

    /**
     * Get vendor-wise purchase analysis.
     *
     * @param int|null $branchId
     * @param string|null $startDate
     * @param string|null $endDate
     * @return array
     */
    public function getVendorAnalysis(?int $branchId = null, ?string $startDate = null, ?string $endDate = null): array;

    /**
     * Get purchase statistics.
     *
     * @param int|null $branchId
     * @param string|null $period
     * @return array
     */
    public function getPurchaseStatistics(?int $branchId = null, ?string $period = null): array;

    /**
     * Generate purchase report.
     *
     * @param array $filters
     * @return array
     */
    public function generatePurchaseReport(array $filters): array;
}
