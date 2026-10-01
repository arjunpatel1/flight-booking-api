<?php

namespace Modules\Dashboard\Services\Dashboard;

use Modules\Dashboard\Enums\AnalyticsPeriod;
use Modules\Dashboard\Enums\SalesAnalyticsFilter;

interface DashboardServiceInterface
{
    /**
     * Get dashboard overview data
     *
     * @return array
     */
    public function overview(AnalyticsPeriod $period = AnalyticsPeriod::Today): array;

    /**
     * Get live operational metrics
     *
     * @return array
     */
    public function operationalMetrics(): array;

    public function monitoring(): array;

    /**
     * Get smart business insights from current operational data.
     *
     * @return array
     */
    public function smartInsights(): array;

    public function demandForecast(): array;

    public function storeSmartInsightSnapshot(?int $generatedBy = null): array;

    public function smartInsightSnapshots(): mixed;

    public function assistant(string $prompt): array;

    /**
     * Get dashboard best performing branches
     *
     * @param AnalyticsPeriod $filter
     * @param int $limit
     * @return array
     */
    public function bestPerformingBranches(AnalyticsPeriod $filter, int $limit = 5): array;

    /**
     * Get dashboard order type distribution
     *
     * @param AnalyticsPeriod $filter
     * @return array
     */
    public function orderTypeDistribution(AnalyticsPeriod $filter): array;

    /**
     * Get sales analytics
     *
     * @param SalesAnalyticsFilter $filter
     * @return array
     */
    public function salesAnalytics(SalesAnalyticsFilter $filter): array;

    /**
     * Order total by status
     *
     * @param AnalyticsPeriod $filter
     * @return array
     */
    public function orderTotalByStatus(AnalyticsPeriod $filter): array;

    /**
     * Payments Overview
     *
     * @param AnalyticsPeriod $filter
     * @return array
     */
    public function paymentsOverview(AnalyticsPeriod $filter): array;

    /**
     * Branch wise sales comparison
     *
     * @param AnalyticsPeriod $filter
     * @return array
     */
    public function branchWiseSalesComparison(AnalyticsPeriod $filter): array;

    /**
     * Cash Movements Overview
     *
     * @param AnalyticsPeriod $filter
     * @return array
     */
    public function cashMovementsOverview(AnalyticsPeriod $filter): array;

    /**
     * Top a selling products
     *
     * @param AnalyticsPeriod $filter
     * @param int $limit
     * @return array
     */
    public function topSellingProducts(AnalyticsPeriod $filter, int $limit = 5): array;

    /**
     * Get hourly sales trend
     *
     * @return array
     */
    public function hourlySalesTrend(): array;

    /**
     * Get low stock alerts
     *
     * @return array
     */
    public function getLowStockAlerts(): array;

    /**
     * Get system health status (database, queue, disk, cache).
     *
     * @return array
     */
    public function systemHealth(): array;

    /**
     * Global search across products, customers, and orders.
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function globalSearch(string $query, int $limit = 10): array;

    /**
     * Get command palette quick actions filtered by permissions.
     *
     * @return array
     */
    public function commandPalette(): array;

}
