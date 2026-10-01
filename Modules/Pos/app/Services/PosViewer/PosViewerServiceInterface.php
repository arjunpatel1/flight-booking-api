<?php

namespace Modules\Pos\Services\PosViewer;

use Modules\Pos\Transformers\Api\V1\Pos\PosProductResource;

interface PosViewerServiceInterface
{
    /**
     * Get POS Screen configuration
     */
    public function getConfiguration(?int $branchId = null, bool $lightweight = false): array;

    /**
     * Get Menu categories and products
     */
    public function getMenuItems(int $menuId, ?string $orderType = null, ?int $tableId = null, ?int $zoneId = null): array;

    /**
     * Get a POS product with modifier detail after the operator selects it.
     */
    public function getMenuProduct(int $menuId, int $productId, ?string $orderType = null, ?int $tableId = null, ?int $zoneId = null): PosProductResource;

    /**
     * Get waiter operational dashboard.
     */
    public function waiterDashboard(?int $branchId = null): array;

    /**
     * Get compact waiter operational assistant cards.
     */
    public function waiterAssistant(?int $branchId = null): array;

    /**
     * Get deterministic restaurant performance intelligence.
     */
    public function performanceIntelligence(?int $branchId = null, string $period = 'today'): array;

    /**
     * Get deterministic restaurant revenue intelligence.
     */
    public function revenueIntelligence(?int $branchId = null, string $period = 'today'): array;

    /**
     * Get tree categories
     */
    public function getCategories(int $menuId): array;

    /**
     * Get products
     */
    public function getProducts(
        int $menuId,
        ?int $priceTypeId = null,
        ?string $orderType = null,
        ?int $tableId = null,
        bool $includeOptions = false,
    ): array;
}
