<?php

return [
    'filtered_records' => 'Filtered records',
    'filtered_quantity' => 'Total quantity',
    'filtered_value' => 'Total value',
    'gross_collected' => 'Gross collected',
    'refunds' => 'Refunds',
    'net_collected' => 'Net collected',
    'reports' => 'Reports',
    'report' => 'Report',
    'saved_filter' => 'Saved Report Filter',
    'unassigned' => 'Unassigned',

    'stock_status' => [
        'healthy' => 'Healthy',
        'low_stock' => 'Low Stock',
        'out_of_stock' => 'Out of Stock',
    ],

    'filters' => [
        'start_date' => 'Start Date',
        'end_date' => 'End Date',
        'order_status' => 'Order Status',
        'order_type' => 'Order Type',
        'payment_status' => 'Payment Status',
        'category' => 'Category',
        'product_name' => 'Product Name',
        'payment_method' => 'Payment Method',
        'provider' => 'Provider',
        'reason' => 'Reason',
        'direction' => 'Direction',
        'menu' => 'Menu',
        'discount_type' => 'Discount Type',
        'waiter' => 'Waiter',
        'order_source' => 'Order Source',
        'payment_channel' => 'Payment Channel',
    ],

    'groups' => [
        'restaurant_sales_reports' => 'Restaurant Sales Reports',
        'inventory_reports' => 'Inventory Reports',
        'pos_reports' => 'POS Reports',
        'system_reports' => 'System Reports',
        'loyalty_overview_reports' => 'Loyalty Overview Reports',
        'loyalty_customer_reports' => 'Loyalty Customer Reports',
        'loyalty_tier_reports' => 'Loyalty Tier Reports',
        'loyalty_rewards_reports' => 'Loyalty Rewards Reports',
        'loyalty_gifts_reports' => 'Loyalty Gifts Reports',
        'loyalty_redemptions_reports' => 'Loyalty Redemptions Reports',
        'loyalty_promotions_reports' => 'Loyalty Promotions Reports',
        'loyalty_financial_roi_reports' => 'Loyalty Financial & ROI Reports',
        'gst_reports' => 'GST Compliance Reports',
    ],

    'definitions' => [
        'sales' => [
            'title' => 'Daily Sales Report',
            'description' => 'A detailed breakdown of daily sales performance within a selected time range.',
        ],

        'products_purchase' => [
            'title' => 'Products Purchase Report',
            'description' => 'This report helps analyze product procurement performance across different periods',
        ],

        'tax' => [
            'title' => 'Tax Report',
            'description' => 'Summarizes collected taxes by order status, type, and payment over a selected period.',
        ],

        'branch_performance' => [
            'title' => 'Branch Performance Report',
            'description' => 'Shows key sales and order metrics for each branch to compare performance over time.',
        ],

        'aggregator_payout_reconciliation' => [
            'title' => 'Aggregator Payout Reconciliation',
            'description' => 'Compares aggregator sales, refunds, received payments, and pending payout by provider.',
        ],

        'finance_reconciliation' => [
            'title' => 'Finance Reconciliation',
            'description' => 'Combines sales, GST/tax, payments, refunds, expenses, cash flow, and aggregator pending payout by branch.',
        ],

        'payments' => [
            'title' => 'Payments Report',
            'description' => 'Breaks down received payments by method to track revenue collection.',
        ],

        'discounts_and_vouchers' => [
            'title' => 'Discounts & Vouchers Report',
            'description' => 'Shows how discounts and vouchers were redeemed, including usage counts and total discounted amounts.',
        ],

        'product_tax' => [
            'title' => 'Product Tax Report',
            'description' => 'Displays the total tax amounts applied to specific products within a selected period.',
        ],

        'ingredient_usage' => [
            'title' => 'Ingredient Usage Report',
            'description' => 'Tracks the total quantity of each ingredient used based on sold products during a selected period',
        ],

        'low_stock_alerts' => [
            'title' => 'Low Stock Alerts',
            'description' => 'Identifies ingredients that are nearing depletion or already finished to prompt timely restocking.',
        ],

        'stock_valuation' => [
            'title' => 'Stock Valuation Report',
            'description' => 'Shows current stock, unit cost, valuation, and low-stock status for each ingredient.',
        ],

        'wastage_cost' => [
            'title' => 'Wastage Cost Report',
            'description' => 'Tracks wasted ingredient quantity, reasons, and cost impact over the selected period.',
        ],

        'register_summary' => [
            'title' => 'Register Summary Report',
            'description' => 'Provides a financial summary of POS registers including sessions, sales, cash movements, and balances.',
        ],

        'cash_movement' => [
            'title' => 'Cash Movement Report',
            'description' => 'Tracks all cash-in and cash-out transactions across POS registers, including users and reasons.',
        ],

        'sales_by_creator' => [
            'title' => 'Sales By Creator Report',
            'description' => 'Shows total sales grouped by the user who created each order, helping track individual employee performance.',
        ],

        'sales_by_cashier' => [
            'title' => 'Sales By Cashier Report',
            'description' => 'Summarizes total sales handled by each cashier, excluding canceled and refunded orders.',
        ],

        'sales_by_waiter' => [
            'title' => 'Sales By Waiter Report',
            'description' => 'Tracks waiter performance by orders, guests, assigned tables, products, and revenue.',
        ],

        'waiter_collection' => [
            'title' => 'Waiter Collection Report',
            'description' => 'Tracks waiter-wise collection, payment method split, tips, and pending collections.',
        ],

        'peak_hour_sales' => [
            'title' => 'Peak Hour Sales Report',
            'description' => 'Shows busiest sales hours by order count, product count, sales, and average order value.',
        ],

        'categorized_products' => [
            'title' => 'Categorized Products Report',
            'description' => 'Shows each category with its total product count — ideal for reviewing product distribution.',
        ],
        'upcoming_orders' => [
            'title' => 'Upcoming Orders Report',
            'description' => 'Displays scheduled orders that are set to be prepared or served at a future time.',
        ],

        'cost_and_revenue_by_order' => [
            'title' => 'Cost & Revenue Report by Order',
            'description' => 'Show each order’s total cost (based on product costs), total revenue (sales), and profit.',
        ],

        'cost_and_revenue_by_product' => [
            'title' => 'Cost & Revenue Report by Product',
            'description' => 'Provides an overview of product performance in terms of quantity sold, cost, and revenue.',
        ],

        'menu_engineering' => [
            'title' => 'Menu Engineering Report',
            'description' => 'Classifies products by sales volume and margin to identify stars, workhorses, puzzles, and low performers.',
        ],

        'slow_moving_products' => [
            'title' => 'Slow Moving Products Report',
            'description' => 'Highlights products with low sales quantity in the selected period so menu cleanup decisions are easier.',
        ],

        'monthly_sales' => [
            'title' => 'Monthly Report',
            'description' => 'Generates one stored monthly Excel workbook with daily sheets, accounting totals, payment breakdowns, and audit-ready summaries.',
        ],

        'loyalty_program_summary' => [
            'title' => 'Loyalty Program Summary',
            'description' => 'Overview of each loyalty program with customer count and points lifecycle totals.',
        ],

        'loyalty_total_earned_points' => [
            'title' => 'Total Earned Points',
            'description' => 'Total loyalty points earned within the selected period.',
        ],

        'loyalty_total_redeemed_points' => [
            'title' => 'Total Redeemed Points',
            'description' => 'Total loyalty points redeemed within the selected period.',
        ],

        'loyalty_total_expired_points' => [
            'title' => 'Total Expired Points',
            'description' => 'Total loyalty points that expired without redemption.',
        ],

        'loyalty_system_points_balance' => [
            'title' => 'System Points Balance',
            'description' => 'Total current points balance across active loyalty customers.',
        ],

        'loyalty_redemption_rate' => [
            'title' => 'Redemption Rate',
            'description' => 'Ratio of redeemed points to earned points per program.',
        ],

        'loyalty_average_points_per_program' => [
            'title' => 'Average Points per Program',
            'description' => 'Average points balance for customers in each program.',
        ],

        'loyalty_points_lifecycle_timeline' => [
            'title' => 'Points Lifecycle Timeline',
            'description' => 'Daily earned vs redeemed vs expired points across loyalty programs.',
        ],

        'loyalty_last_activity' => [
            'title' => 'Last Loyalty Activity',
            'description' => 'Latest loyalty activity per customer with last earn/redeem timestamps.',
        ],

        'loyalty_inactive_customers' => [
            'title' => 'Inactive Loyalty Customers',
            'description' => 'Customers with no loyalty activity recently and their inactivity duration.',
        ],

        'loyalty_no_redemptions' => [
            'title' => 'Customers with No Redemptions',
            'description' => 'Customers who have never redeemed loyalty points.',
        ],

        'loyalty_top_customers_by_points' => [
            'title' => 'Top Customers by Points',
            'description' => 'Customers ranked by lifetime points and current balance.',
        ],

        'loyalty_top_customers_by_spend' => [
            'title' => 'Top Customers by Spend',
            'description' => 'Loyalty customers ranked by total spend and orders.',
        ],

        'loyalty_top_customers_by_orders' => [
            'title' => 'Top Customers by Orders',
            'description' => 'Loyalty customers ranked by total orders and average order value.',
        ],

        'loyalty_tier_customer_distribution' => [
            'title' => 'Customers by Tier Distribution',
            'description' => 'How loyalty customers are distributed across tiers.',
        ],

        'loyalty_tier_redemption_rate' => [
            'title' => 'Redemption Rate per Tier',
            'description' => 'Earned vs redeemed points segmented by tier.',
        ],

        'loyalty_most_redeemed_rewards' => [
            'title' => 'Most Redeemed Rewards',
            'description' => 'Rewards with the highest redemption count.',
        ],

        'loyalty_least_used_rewards' => [
            'title' => 'Least Used Rewards',
            'description' => 'Rewards with minimal redemption counts.',
        ],

        'loyalty_never_redeemed_rewards' => [
            'title' => 'Never Redeemed Rewards',
            'description' => 'Rewards that were never redeemed.',
        ],

        'loyalty_rewards_by_type' => [
            'title' => 'Rewards by Type',
            'description' => 'Rewards grouped by type with redemption totals.',
        ],

        'loyalty_rewards_by_tier' => [
            'title' => 'Rewards by Tier',
            'description' => 'Rewards available per loyalty tier.',
        ],

        'loyalty_rewards_by_program' => [
            'title' => 'Rewards by Program',
            'description' => 'Rewards grouped by loyalty program.',
        ],

        'loyalty_available_gifts' => [
            'title' => 'Available Gifts',
            'description' => 'Gifts that are available for use.',
        ],

        'loyalty_used_gifts' => [
            'title' => 'Used Gifts',
            'description' => 'Gifts that have been redeemed.',
        ],

        'loyalty_expired_gifts' => [
            'title' => 'Expired Gifts',
            'description' => 'Gifts that expired unused.',
        ],

        'loyalty_gift_usage_rate' => [
            'title' => 'Gift Usage Rate',
            'description' => 'Usage rate of issued gifts.',
        ],

        'loyalty_unused_gifts_per_customer' => [
            'title' => 'Unused Gifts per Customer',
            'description' => 'Unused gifts grouped by customer.',
        ],

        'loyalty_redemptions_by_status' => [
            'title' => 'Redemptions by Status',
            'description' => 'Redemptions grouped by status.',
        ],

        'loyalty_redemptions_by_program' => [
            'title' => 'Redemptions by Program',
            'description' => 'Redemptions grouped by loyalty program.',
        ],

        'loyalty_average_points_per_redemption' => [
            'title' => 'Average Points per Redemption',
            'description' => 'Average points spent per redemption.',
        ],

        'loyalty_active_promotions' => [
            'title' => 'Active Promotions',
            'description' => 'Currently active promotions.',
        ],

        'loyalty_expired_promotions' => [
            'title' => 'Expired Promotions',
            'description' => 'Promotions that ended.',
        ],

        'loyalty_promotion_usage' => [
            'title' => 'Promotion Usage',
            'description' => 'Usage count per promotion.',
        ],

        'loyalty_highest_impact_promotions' => [
            'title' => 'Highest Impact Promotions',
            'description' => 'Promotions with highest points generated.',
        ],

        'loyalty_bonus_vs_multiplier_comparison' => [
            'title' => 'Bonus vs Multiplier Comparison',
            'description' => 'Compares bonus and multiplier promotions.',
        ],

        'loyalty_category_boost_promotions' => [
            'title' => 'Category Boost Promotions',
            'description' => 'Promotions boosting specific categories.',
        ],

        'loyalty_new_member_promotions' => [
            'title' => 'New Member Promotions',
            'description' => 'Promotions for new loyalty members.',
        ],

        'loyalty_free_items_cost' => [
            'title' => 'Free Items Cost',
            'description' => 'Cost of free items issued.',
        ],

        'loyalty_revenue_from_loyalty_customers' => [
            'title' => 'Revenue from Loyalty Customers',
            'description' => 'Revenue generated by loyalty customers.',
        ],

        'loyalty_revenue_before_after_loyalty' => [
            'title' => 'Revenue Before vs After Loyalty',
            'description' => 'Revenue comparison for loyalty vs non-loyalty orders.',
        ],

        'loyalty_average_order_value_loyalty_customers' => [
            'title' => 'Average Order Value (Loyalty Customers)',
            'description' => 'Average order value for loyalty customers.',
        ],

        'gst_sales_summary' => [
            'title' => 'GST Sales Summary',
            'description' => 'Period-wise summary of taxable sales with CGST, SGST, IGST, and Cess breakdown.',
        ],
        'gst_rate_wise_sales' => [
            'title' => 'Rate-Wise GST Sales',
            'description' => 'Sales and GST collected grouped by GST rate slab, showing CGST/SGST/IGST split.',
        ],
        'gst_hsn_summary' => [
            'title' => 'HSN/SAC Summary',
            'description' => 'Product-wise GST summary with HSN codes, taxable value, and CGST/SGST/IGST breakdown.',
        ],
        'gst_invoice_register' => [
            'title' => 'Tax Invoice Register',
            'description' => 'Invoice-wise GST register showing CGST, SGST, IGST, and Cess per completed order.',
        ],
        'gst_b2b_sales' => [
            'title' => 'B2B Sales Register',
            'description' => 'Invoice-wise register of B2B sales made to GST-registered dealers.',
        ],
        'gst_b2c_sales' => [
            'title' => 'B2C Sales Register',
            'description' => 'Invoice-wise register of B2C sales made to unregistered customers.',
        ],
        'gst_collection' => [
            'title' => 'GST Collection Report',
            'description' => 'Tax-wise GST collection details grouped by tax name and GST type.',
        ],
        'gst_gstr1_summary' => [
            'title' => 'GSTR-1 Summary',
            'description' => 'Outward supply summary grouped by B2B, B2CL, and B2CS for GSTR-1 filing reference.',
        ],
        'gst_gstr3b_summary' => [
            'title' => 'GSTR-3B Summary',
            'description' => 'Month-wise GST liability summary aligned to GSTR-3B return format.',
        ],
        'gst_credit_notes' => [
            'title' => 'Credit Notes Register',
            'description' => 'Register of credit notes issued for cancelled or refunded orders.',
        ],
        'gst_debit_notes' => [
            'title' => 'Debit Notes Register',
            'description' => 'Register of debit notes for orders with pending dues or additional charges.',
        ],
        'gst_cancelled_invoices' => [
            'title' => 'Cancelled Invoices Register',
            'description' => 'Complete register of cancelled invoices with GST amounts for reversal tracking.',
        ],
        'gst_branch_wise' => [
            'title' => 'Branch Wise GST Report',
            'description' => 'GST collection summary grouped by branch, showing CGST, SGST, and IGST per outlet.',
        ],
        'gst_order_type_wise' => [
            'title' => 'Order Type Wise GST',
            'description' => 'GST collection broken down by order type — Dine-In, Takeaway, Delivery, and Self Service.',
        ],
        'gst_payment_method' => [
            'title' => 'Payment Method GST Report',
            'description' => 'GST collected grouped by payment method — Cash, UPI, Card, and Wallet.',
        ],
        'gst_item_wise_sales' => [
            'title' => 'Item Wise GST Sales',
            'description' => 'Product-level GST report showing taxable value, CGST, SGST, and IGST per item.',
        ],
        'gst_audit_report' => [
            'title' => 'GST Audit Report',
            'description' => 'Detects missing GST, negative tax amounts, and invoice total mismatches for compliance audit.',
        ],
        'gst_exception_report' => [
            'title' => 'GST Exception Report',
            'description' => 'Flags unclassified GST types, tax on cancelled orders, and zero-rate anomalies.',
        ],
    ],

    'menu_engineering' => [
        'classifications' => [
            'star' => 'Star',
            'workhorse' => 'Workhorse',
            'puzzle' => 'Puzzle',
            'dog' => 'Low Performer',
        ],
        'recommendations' => [
            'star' => 'Keep visible and protect quality. Consider featuring it in combos.',
            'workhorse' => 'High demand with lower margin. Review recipe cost, portioning, or price.',
            'puzzle' => 'Good margin but low demand. Improve placement, imagery, or staff recommendation.',
            'dog' => 'Low demand and low margin. Consider replacing, repricing, or removing.',
        ],
    ],

    'slow_moving_products' => [
        'recommendation' => 'Review placement, recipe cost, price, photo, staff push, or remove from menu if demand stays low.',
    ],

    'export_failed' => 'Export failed. Please try again later.',

    'gst_dashboard' => [
        'title' => 'GST Dashboard',
        'description' => 'GST compliance overview and tax analytics',
        'subtitle' => 'GST summaries, invoice registers, filing reports, and exception checks.',
        'search_placeholder' => 'Search GST reports',
        'no_permission' => 'You do not have permission to view GST reports.',
        'no_results' => 'No GST reports match your search.',
        'total_taxable_sales' => 'Total Taxable Sales',
        'total_gst' => 'Total GST',
        'total_cgst' => 'Total CGST',
        'total_sgst' => 'Total SGST',
        'total_igst' => 'Total IGST',
        'total_cess' => 'Total CESS',
        'gross_total' => 'Gross Total',
        'b2b_orders' => 'B2B Orders',
        'b2c_orders' => 'B2C Orders',
        'total_orders' => 'Total Orders',
        'daily_trend' => 'Daily GST Trend',
        'monthly_collection' => 'Monthly GST Collection',
        'by_order_type' => 'GST by Order Type',
        'from' => 'From',
        'to' => 'To',
        'apply' => 'Apply',
        'total_gst_label' => 'Total GST',
        'taxable_amount' => 'Taxable Amount',
        'gross_total_label' => 'Gross Total',
        'load_error' => 'Failed to load GST Dashboard',
        'network_error' => 'Network error. Please check your connection.',
        'server_error' => 'Server error. Please try again later.',
        'unauthorized_error' => 'Unauthorized access. Please check your permissions.',
    ],
];
