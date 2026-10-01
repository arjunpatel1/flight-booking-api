<?php

use Modules\User\Enums\{PermissionAction as Action};

return [
    'permissions' => [
        "dashboards" => [
            Action::TotalSales,
            Action::TotalOrders,
            Action::TotalActiveOrders,
            Action::AverageOrderValue,
            Action::TotalUsers,
            Action::TotalMenus,
            Action::TotalProducts,
            Action::TotalCategories,
            Action::SalesAnalytics,
            Action::BestPerformingBranches,
            Action::OrderTypeDistribution,
            Action::OrderTotalByStatus,
            Action::PaymentsOverview,
            Action::BranchWiseSalesComparison,
            Action::CashMovementsOverview,
            Action::TopSellingProducts,
            Action::LowStockAlerts,
            Action::Live,
            Action::Analytics,
            Action::Monitoring
        ]
    ],
];
