<?php

namespace Modules\Pos\Services\KitchenStation;

use Illuminate\Support\Collection;
use Modules\Pos\Models\KitchenStation;
use Modules\Pos\Models\KitchenStationOrderProduct;

interface KitchenStationServiceInterface
{
    /**
     * Get all active stations for a branch.
     */
    public function getActiveStations(?int $branchId = null): Collection;

    /**
     * Get kitchen stations for management.
     */
    public function getStations(?int $branchId = null, bool $activeOnly = false): Collection;

    /**
     * Create a kitchen station.
     */
    public function store(array $data): KitchenStation;

    /**
     * Update a kitchen station.
     */
    public function update(int $id, array $data): KitchenStation;

    /**
     * Delete kitchen stations.
     */
    public function destroy(int|array|string $ids): bool;

    /**
     * Get form metadata for station management.
     */
    public function getFormMeta(?int $branchId = null): array;

    /**
     * Route order products to appropriate stations.
     */
    public function routeOrderProducts(array $orderProductIds, int $branchId): Collection;

    /**
     * Get items for a specific station.
     */
    public function getStationItems(int $stationId, ?string $status = null): Collection;

    /**
     * Start prep for an item.
     */
    public function startPrep(int $stationOrderProductId, ?int $userId = null, ?int $stationId = null): KitchenStationOrderProduct;

    /**
     * Complete/bump an item.
     */
    public function completeItem(int $stationOrderProductId, ?int $userId = null, ?string $notes = null, ?int $stationId = null): KitchenStationOrderProduct;

    /**
     * Recall (un-bump) a completed item back to the preparing queue.
     */
    public function recallItem(int $stationOrderProductId, ?int $userId = null, ?int $stationId = null): KitchenStationOrderProduct;

    /**
     * Complete/bump multiple station items.
     */
    public function completeItems(array $stationOrderProductIds, ?int $userId = null, ?string $notes = null, ?int $stationId = null): Collection;

    /**
     * Get station statistics.
     */
    public function getStationStats(int $stationId, ?string $from = null, ?string $to = null): array;

    /**
     * Update item priority.
     */
    public function updateItemPriority(int $stationOrderProductId, int $priority, ?int $stationId = null): KitchenStationOrderProduct;

    /**
     * Get delayed items across all stations.
     */
    public function getDelayedItems(?int $branchId = null): Collection;

    /**
     * Auto-bump items that have been completed for too long.
     */
    public function autoBumpCompletedItems(?int $branchId = null): int;
}
