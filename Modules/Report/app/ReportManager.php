<?php

namespace Modules\Report;

use Modules\Report\Reports\Gst\GstAuditReport;
use Modules\Report\Reports\Gst\GstB2BSalesReport;
use Modules\Report\Reports\Gst\GstB2CSalesReport;
use Modules\Report\Reports\Gst\GstBranchWiseReport;
use Modules\Report\Reports\Gst\GstCancelledInvoicesReport;
use Modules\Report\Reports\Gst\GstCollectionReport;
use Modules\Report\Reports\Gst\GstCreditNotesReport;
use Modules\Report\Reports\Gst\GstDebitNotesReport;
use Modules\Report\Reports\Gst\GstExceptionReport;
use Modules\Report\Reports\Gst\GstGstr1SummaryReport;
use Modules\Report\Reports\Gst\GstGstr3BSummaryReport;
use Modules\Report\Reports\Gst\GstHsnSummaryReport;
use Modules\Report\Reports\Gst\GstInvoiceRegisterReport;
use Modules\Report\Reports\Gst\GstItemWiseSalesReport;
use Modules\Report\Reports\Gst\GstOrderTypeWiseReport;
use Modules\Report\Reports\Gst\GstPaymentMethodReport;
use Modules\Report\Reports\Gst\GstRateWiseSalesReport;
use Modules\Report\Reports\Gst\GstSalesSummaryReport;
use Modules\Report\Reports\BranchPerformanceReport;
use Modules\Report\Reports\AggregatorPayoutReconciliationReport;
use Modules\Report\Reports\CashMovementReport;
use Modules\Report\Reports\CategorizedProductsReport;
use Modules\Report\Reports\CostRevenueByOrderReport;
use Modules\Report\Reports\CostRevenueReportByProductReport;
use Modules\Report\Reports\DiscountsAndVouchersReport;
use Modules\Report\Reports\FinanceReconciliationReport;
use Modules\Report\Reports\IngredientUsageReport;
use Modules\Report\Reports\LowStockAlertsReport;
use Modules\Report\Reports\MenuEngineeringReport;
use Modules\Report\Reports\Loyalty\ActivePromotionsReport;
use Modules\Report\Reports\Loyalty\AvailableGiftsReport;
use Modules\Report\Reports\Loyalty\AverageOrderValueLoyaltyCustomersReport;
use Modules\Report\Reports\Loyalty\AveragePointsPerProgramReport;
use Modules\Report\Reports\Loyalty\AveragePointsPerRedemptionReport;
use Modules\Report\Reports\Loyalty\BonusVsMultiplierComparisonReport;
use Modules\Report\Reports\Loyalty\CategoryBoostPromotionsReport;
use Modules\Report\Reports\Loyalty\ExpiredGiftsReport;
use Modules\Report\Reports\Loyalty\ExpiredPromotionsReport;
use Modules\Report\Reports\Loyalty\FreeItemsCostReport;
use Modules\Report\Reports\Loyalty\GiftUsageRateReport;
use Modules\Report\Reports\Loyalty\HighestImpactPromotionsReport;
use Modules\Report\Reports\Loyalty\InactiveLoyaltyCustomersReport;
use Modules\Report\Reports\Loyalty\LeastUsedRewardsReport;
use Modules\Report\Reports\Loyalty\LoyaltyLastActivityReport;
use Modules\Report\Reports\Loyalty\LoyaltyProgramSummaryReport;
use Modules\Report\Reports\Loyalty\MostRedeemedRewardsReport;
use Modules\Report\Reports\Loyalty\NeverRedeemedRewardsReport;
use Modules\Report\Reports\Loyalty\NewMemberPromotionsReport;
use Modules\Report\Reports\Loyalty\NoRedemptionsReport;
use Modules\Report\Reports\Loyalty\PointsLifecycleTimelineReport;
use Modules\Report\Reports\Loyalty\PromotionUsageReport;
use Modules\Report\Reports\Loyalty\RedemptionRateReport;
use Modules\Report\Reports\Loyalty\RedemptionsByProgramReport;
use Modules\Report\Reports\Loyalty\RedemptionsByStatusReport;
use Modules\Report\Reports\Loyalty\RevenueBeforeAfterLoyaltyReport;
use Modules\Report\Reports\Loyalty\RevenueFromLoyaltyCustomersReport;
use Modules\Report\Reports\Loyalty\RewardsByProgramReport;
use Modules\Report\Reports\Loyalty\RewardsByTierReport;
use Modules\Report\Reports\Loyalty\RewardsByTypeReport;
use Modules\Report\Reports\Loyalty\SystemPointsBalanceReport;
use Modules\Report\Reports\Loyalty\TierCustomerDistributionReport;
use Modules\Report\Reports\Loyalty\TierRedemptionRateReport;
use Modules\Report\Reports\Loyalty\TopCustomersByPointsReport;
use Modules\Report\Reports\Loyalty\TotalEarnedPointsReport;
use Modules\Report\Reports\Loyalty\TotalExpiredPointsReport;
use Modules\Report\Reports\Loyalty\TotalRedeemedPointsReport;
use Modules\Report\Reports\Loyalty\UnusedGiftsPerCustomerReport;
use Modules\Report\Reports\Loyalty\UsedGiftsReport;
use Modules\Report\Reports\PaymentsReport;
use Modules\Report\Reports\PeakHourSalesReport;
use Modules\Report\Reports\ProductTaxReport;
use Modules\Report\Reports\ProductsPurchaseReport;
use Modules\Report\Reports\RegisterSummaryReport;
use Modules\Report\Reports\SalesByCashierReport;
use Modules\Report\Reports\SalesByCreatorReport;
use Modules\Report\Reports\SalesByWaiterReport;
use Modules\Report\Reports\SalesReport;
use Modules\Report\Reports\SlowMovingProductsReport;
use Modules\Report\Reports\StockValuationReport;
use Modules\Report\Reports\TaxReport;
use Modules\Report\Reports\UpcomingOrdersReport;
use Modules\Report\Reports\WaiterCollectionReport;
use Modules\Report\Reports\WastageCostReport;

class ReportManager
{
    /**
     * Registered report classes keyed by report key.
     *
     * Report instances carry request-specific auth, branch, and currency state,
     * so the manager must never reuse one instance across users.
     *
     * @var array<string, class-string<Report>>
     */
    protected array $registeredReports = [];

    /**
     * Array of available reports.
     *
     * @var array
     */
    private array $reports = [
        GstSalesSummaryReport::class,
        GstRateWiseSalesReport::class,
        GstHsnSummaryReport::class,
        GstInvoiceRegisterReport::class,
        GstB2BSalesReport::class,
        GstB2CSalesReport::class,
        GstCollectionReport::class,
        GstGstr1SummaryReport::class,
        GstGstr3BSummaryReport::class,
        GstCreditNotesReport::class,
        GstDebitNotesReport::class,
        GstCancelledInvoicesReport::class,
        GstBranchWiseReport::class,
        GstOrderTypeWiseReport::class,
        GstPaymentMethodReport::class,
        GstItemWiseSalesReport::class,
        GstAuditReport::class,
        GstExceptionReport::class,
        SalesReport::class,
        ProductsPurchaseReport::class,
        TaxReport::class,
        ProductTaxReport::class,
        BranchPerformanceReport::class,
        AggregatorPayoutReconciliationReport::class,
        FinanceReconciliationReport::class,
        PaymentsReport::class,
        DiscountsAndVouchersReport::class,
        IngredientUsageReport::class,
        LowStockAlertsReport::class,
        StockValuationReport::class,
        WastageCostReport::class,
        RegisterSummaryReport::class,
        CashMovementReport::class,
        SalesByCreatorReport::class,
        SalesByCashierReport::class,
        SalesByWaiterReport::class,
        WaiterCollectionReport::class,
        PeakHourSalesReport::class,
        CategorizedProductsReport::class,
        UpcomingOrdersReport::class,
        CostRevenueByOrderReport::class,
        CostRevenueReportByProductReport::class,
        MenuEngineeringReport::class,
        SlowMovingProductsReport::class,
        LoyaltyProgramSummaryReport::class,
        TotalEarnedPointsReport::class,
        TotalRedeemedPointsReport::class,
        TotalExpiredPointsReport::class,
        SystemPointsBalanceReport::class,
        RedemptionRateReport::class,
        AveragePointsPerProgramReport::class,
        PointsLifecycleTimelineReport::class,
        LoyaltyLastActivityReport::class,
        InactiveLoyaltyCustomersReport::class,
        NoRedemptionsReport::class,
        TopCustomersByPointsReport::class,
        TierCustomerDistributionReport::class,
        TierRedemptionRateReport::class,
        RevenueFromLoyaltyCustomersReport::class,
        RevenueBeforeAfterLoyaltyReport::class,
        AverageOrderValueLoyaltyCustomersReport::class,
        FreeItemsCostReport::class,
        MostRedeemedRewardsReport::class,
        LeastUsedRewardsReport::class,
        NeverRedeemedRewardsReport::class,
        RewardsByTypeReport::class,
        RewardsByTierReport::class,
        RewardsByProgramReport::class,
        AvailableGiftsReport::class,
        UsedGiftsReport::class,
        ExpiredGiftsReport::class,
        GiftUsageRateReport::class,
        UnusedGiftsPerCustomerReport::class,
        RedemptionsByStatusReport::class,
        RedemptionsByProgramReport::class,
        AveragePointsPerRedemptionReport::class,
        ActivePromotionsReport::class,
        ExpiredPromotionsReport::class,
        PromotionUsageReport::class,
        HighestImpactPromotionsReport::class,
        BonusVsMultiplierComparisonReport::class,
        CategoryBoostPromotionsReport::class,
        NewMemberPromotionsReport::class,
    ];

    /**
     * Get instance from ReportManager.
     * Delegates to the IoC container so Octane workers never share state across requests.
     */
    public static function getInstance(): ReportManager
    {
        return app(static::class);
    }

    /**
     * Register groups
     *
     * @param bool $force ;
     * @return void
     */
    public function register(bool $force = false): void
    {
        if (count($this->registeredReports) == 0 || $force) {
            foreach ($this->reports as $report) {
                $object = new $report;
                $this->registeredReports[$object->key()] = $report;
            }
        }
    }

    /**
     * Determine if group exists
     *
     * @param string $key
     * @return bool
     */
    public function reportExists(string $key): bool
    {
        return array_key_exists($key, $this->registeredReports());
    }

    /**
     * Register single report
     *
     * @param string|null $key
     * @return Report|array
     */
    public function registeredReports(?string $key = null): array|Report
    {
        if (is_null($key)) {
            return array_map(fn(string $report) => new $report, $this->registeredReports);
        }

        return new $this->registeredReports[$key];
    }
}
