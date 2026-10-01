<?php
return [
    "waiter_settlements" => [
        "waiter_id" => "Waiter",
        "business_date" => "Date",
        "settled_amount" => "Settled Amount",
        "notes" => "Notes",
    ],
    "gst_sales_summary" => [
        "period"         => "Period",
        "total_orders"   => "Total Orders",
        "taxable_amount" => "Taxable Amount",
        "total_cgst"     => "CGST",
        "total_sgst"     => "SGST",
        "total_igst"     => "IGST",
        "total_cess"     => "Cess",
        "total_tax"      => "Total Tax",
        "gross_total"    => "Gross Total",
    ],

    "gst_collection" => [
        "tax_name"        => "Tax Name",
        "gst_type"        => "GST Type",
        "tax_rate"        => "Rate (%)",
        "total_entries"   => "Transactions",
        "total_collected" => "Amount Collected",
    ],

    "gst_rate_wise_sales" => [
        "tax_rate"     => "GST Rate (%)",
        "total_orders" => "Orders",
        "cgst"         => "CGST",
        "sgst"         => "SGST",
        "igst"         => "IGST",
        "cess"         => "Cess",
        "total_gst"    => "Total GST",
    ],

    "gst_invoice_register" => [
        "date"           => "Invoice Date",
        "reference"      => "Invoice #",
        "order_type"     => "Order Type",
        "taxable_amount" => "Taxable Amount",
        "cgst"           => "CGST",
        "sgst"           => "SGST",
        "igst"           => "IGST",
        "cess"           => "Cess",
        "total"          => "Invoice Total",
    ],

    "gst_credit_notes" => [
        "date"           => "Date",
        "reference"      => "Credit Note #",
        "order_type"     => "Order Type",
        "taxable_amount" => "Taxable Amount",
        "cgst"           => "CGST",
        "sgst"           => "SGST",
        "igst"           => "IGST",
        "cess"           => "Cess",
        "total"          => "Total",
    ],

    "gst_hsn_summary" => [
        "hsn_code"      => "HSN/SAC Code",
        "product_name"  => "Product / Service",
        "uom"           => "UOM",
        "total_qty"     => "Quantity",
        "taxable_value" => "Taxable Value",
        "cgst_rate"     => "CGST Rate",
        "cgst_amount"   => "CGST Amount",
        "sgst_rate"     => "SGST Rate",
        "sgst_amount"   => "SGST Amount",
        "igst_rate"     => "IGST Rate",
        "igst_amount"   => "IGST Amount",
        "total_tax"     => "Total Tax",
        "total_value"   => "Total Value",
    ],

    "gst_b2b_sales" => [
        "date"           => "Invoice Date",
        "reference"      => "Invoice #",
        "gstin"          => "Buyer GSTIN",
        "customer_name"  => "Buyer Name",
        "order_type"     => "Order Type",
        "taxable_amount" => "Taxable Amount",
        "total_cgst"     => "CGST",
        "total_sgst"     => "SGST",
        "total_igst"     => "IGST",
        "total_cess"     => "Cess",
        "total_tax"      => "Total Tax",
        "total"          => "Invoice Total",
    ],

    "gst_b2c_sales" => [
        "date"           => "Invoice Date",
        "reference"      => "Invoice #",
        "order_type"     => "Order Type",
        "taxable_amount" => "Taxable Amount",
        "total_cgst"     => "CGST",
        "total_sgst"     => "SGST",
        "total_igst"     => "IGST",
        "total_cess"     => "Cess",
        "total_tax"      => "Total Tax",
        "total"          => "Invoice Total",
    ],

    "gst_gstr1_summary" => [
        "supply_type"    => "Supply Type",
        "total_invoices" => "No. of Invoices",
        "taxable_amount" => "Taxable Amount",
        "total_cgst"     => "CGST",
        "total_sgst"     => "SGST",
        "total_igst"     => "IGST",
        "total_cess"     => "Cess",
        "total_tax"      => "Total Tax",
        "gross_total"    => "Gross Total",
    ],

    "gst_gstr3b_summary" => [
        "month"          => "Month",
        "total_orders"   => "Total Orders",
        "taxable_amount" => "Taxable Amount",
        "total_cgst"     => "CGST",
        "total_sgst"     => "SGST",
        "total_igst"     => "IGST",
        "total_cess"     => "Cess",
        "total_tax"      => "Total Tax",
        "gross_total"    => "Gross Total",
    ],

    "gst_debit_notes" => [
        "date"           => "Date",
        "reference"      => "Debit Note #",
        "order_type"     => "Order Type",
        "due_amount"     => "Due Amount",
        "taxable_amount" => "Taxable Amount",
        "total_cgst"     => "CGST",
        "total_sgst"     => "SGST",
        "total_igst"     => "IGST",
        "total_cess"     => "Cess",
        "total_tax"      => "Total Tax",
        "total"          => "Total",
    ],

    "gst_cancelled_invoices" => [
        "date"           => "Cancelled Date",
        "reference"      => "Invoice #",
        "order_type"     => "Order Type",
        "taxable_amount" => "Taxable Amount",
        "total_cgst"     => "CGST",
        "total_sgst"     => "SGST",
        "total_igst"     => "IGST",
        "total_cess"     => "Cess",
        "total_tax"      => "Total Tax",
        "total"          => "Total",
    ],

    "gst_branch_wise" => [
        "branch_name"    => "Branch",
        "total_orders"   => "Total Orders",
        "taxable_amount" => "Taxable Amount",
        "total_cgst"     => "CGST",
        "total_sgst"     => "SGST",
        "total_igst"     => "IGST",
        "total_cess"     => "Cess",
        "total_tax"      => "Total Tax",
        "gross_total"    => "Gross Total",
    ],

    "gst_order_type_wise" => [
        "order_type"     => "Order Type",
        "total_orders"   => "Total Orders",
        "taxable_amount" => "Taxable Amount",
        "total_cgst"     => "CGST",
        "total_sgst"     => "SGST",
        "total_igst"     => "IGST",
        "total_cess"     => "Cess",
        "total_tax"      => "Total Tax",
        "gross_total"    => "Gross Total",
    ],

    "gst_payment_method" => [
        "payment_method"     => "Payment Method",
        "total_transactions" => "Transactions",
        "taxable_amount"     => "Taxable Amount",
        "total_tax"          => "Total GST",
        "total_paid"         => "Total Paid",
    ],

    "gst_item_wise_sales" => [
        "product_name"  => "Product Name",
        "hsn_code"      => "HSN/SAC Code",
        "total_qty"     => "Quantity",
        "unit_price"    => "Unit Price",
        "taxable_value" => "Taxable Value",
        "total_cgst"    => "CGST",
        "total_sgst"    => "SGST",
        "total_igst"    => "IGST",
        "total_cess"    => "Cess",
        "total_tax"     => "Total Tax",
        "total_value"   => "Total Value",
    ],

    "gst_audit_report" => [
        "date"           => "Date",
        "reference"      => "Invoice #",
        "issue_type"     => "Issue",
        "severity"       => "Severity",
        "total"          => "Invoice Total",
        "total_tax"      => "Tax Amount",
        "recommendation" => "Recommendation",
    ],

    "gst_exception_report" => [
        "date"            => "Date",
        "order_reference" => "Order #",
        "tax_name"        => "Tax Name",
        "gst_type"        => "GST Type",
        "exception_type"  => "Exception",
        "tax_amount"      => "Tax Amount",
        "status"          => "Order Status",
    ],

    "sales" => [
        "period" => "Date",
        "total_orders" => "Total Orders",
        "total_products" => "Total Products",
        "subtotal" => "Subtotal",
        "tax" => "Tax",
        "total" => "Total",
        "average_order_value" => "Average Order Value",
    ],

    "products_purchase" => [
        "period" => "Period",
        "product" => "Product",
        "quantity" => "Quantity",
        "total" => "Total",
    ],

    "tax" => [
        "period" => "Period",
        "tax_name" => "Tax Name",
        "total_orders" => "Total Orders",
        "total" => "Total",
    ],

    "branch_performance" => [
        "period" => "Period",
        "branch_name" => "Branch Name",
        "total_orders" => "Total Orders",
        "total" => "Total",
    ],

    "aggregator_payout_reconciliation" => [
        "period" => "Period",
        "provider" => "Provider",
        "total_orders" => "Total Orders",
        "gross_sales" => "Gross Sales",
        "refunds" => "Refunds",
        "net_sales" => "Net Sales",
        "payments_received" => "Payments Received",
        "pending_payout" => "Pending Payout",
    ],

    "finance_reconciliation" => [
        "period" => "Period",
        "branch" => "Branch",
        "total_orders" => "Total Orders",
        "gross_sales" => "Gross Sales",
        "tax_collected" => "Tax / GST Collected",
        "payments_received" => "Payments Received",
        "refunds" => "Refunds",
        "cash_in" => "Cash In",
        "cash_out" => "Cash Out / Expenses",
        "aggregator_pending_payout" => "Aggregator Pending Payout",
        "net_cash_flow" => "Net Cash Flow",
    ],

    "payments" => [
        "period" => "Period",
        "payment_method" => 'Payment Method',
        "total_paid" => "Total Paid",
        "total" => "Total",
    ],

    "discounts_and_vouchers" => [
        "period" => "Period",
        "discount" => "Discount / Voucher",
        "discount_type" => "Type",
        "total_orders" => "Total Orders",
        "total_discount" => "Total Discount",
    ],

    "product_tax" => [
        "period" => "Period",
        "tax_name" => "Tax Name",
        "product_name" => "Product Name",
        "total_products" => "Total Products",
        "total" => "Total",
    ],

    "ingredient_usage" => [
        "date" => "Date",
        "period" => "Period",
        "ingredient_name" => "Ingredient Name",
        "total_used" => "Total Used",
    ],

    "low_stock_alerts" => [
        "ingredient_name" => "Ingredient Name",
        "current_stock" => "Current Stock",
        "alert_quantity" => "Alert Quantity",
    ],

    "stock_valuation" => [
        "ingredient_name" => "Ingredient Name",
        "current_stock" => "Current Stock",
        "unit_cost" => "Unit Cost",
        "stock_value" => "Stock Value",
        "alert_quantity" => "Alert Quantity",
        "stock_status" => "Stock Status",
    ],

    "wastage_cost" => [
        "period" => "Period",
        "ingredient_name" => "Ingredient Name",
        "reason" => "Reason",
        "total_wasted" => "Total Wasted",
        "waste_count" => "Wastage Entries",
        "total_cost" => "Total Cost",
    ],

    "register_summary" => [
        "date" => "Date Range",
        'register_name' => "Register Name",
        'sessions_count' => "Sessions Count",
        'orders_count' => "Orders Count",
        'system_cash_sales' => "System Cash Sales",
        'system_card_sales' => "System Card Sales",
        'system_other_sales' => "System Other Sales",
        'total_sales' => "Total Sales",
        'total_refunds' => "Total Refunds",
    ],

    "cash_movement" => [
        "period" => "Period",
        'register_name' => "Register Name",
        "user_name" => "User",
        "reason" => "Reason",
        "direction" => "Direction",
        "amount" => "Amount",
    ],

    "sales_by_creator" => [
        "period" => "Period",
        "creator" => "Creator",
        "total_orders" => "Total Orders",
        "total_products" => "Total Products",
        "subtotal" => "Subtotal",
        "tax" => "Tax",
        "total" => "Total",
    ],

    "sales_by_waiter" => [
        "period" => "Period",
        "waiter" => "Waiter",
        "total_orders" => "Total Orders",
        "completed_orders" => "Completed Orders",
        "active_orders" => "Active Orders",
        "total_guests" => "Total Guests",
        "assigned_tables" => "Assigned Tables",
        "total_products" => "Total Products",
        "subtotal" => "Subtotal",
        "tax" => "Tax",
        "total" => "Total",
        "average_order_value" => "Average Order Value",
        "average_service_minutes" => "Avg Service Minutes",
    ],

    "waiter_collection" => [
        "period" => "Period",
        "waiter" => "Waiter",
        "orders_served" => "Orders Served",
        "tables_served" => "Tables Served",
        "sales_total" => "Sales Total",
        "collection_total" => "Collection Total",
        "cash_total" => "Cash Collection",
        "upi_total" => "UPI Collection",
        "card_total" => "Card Collection",
        "tips_total" => "Tips",
        "pending_total" => "Pending Collection",
        "average_bill_value" => "Average Bill Value",
    ],

    "peak_hour_sales" => [
        "hour" => "Hour",
        "total_orders" => "Total Orders",
        "total_products" => "Total Products",
        "total_sales" => "Total Sales",
        "average_order_value" => "Average Order Value",
    ],

    "categorized_products" => [
        "category" => "Category",
        "products_count" => "Products Count",
    ],

    "upcoming_orders" => [
        "period" => "Period",
        "total_orders" => "Total Orders",
        "total_products" => "Total Products",
        "subtotal" => "Subtotal",
        "tax" => "Tax",
        "total" => "Total",
    ],

    "cost_and_revenue_by_order" => [
        "period" => "Period",
        "total_orders" => "Total Orders",
        "total_products" => "Total Products",
        "total_cost" => "Total Cost",
        "revenue" => "Revenue",
    ],

    "cost_and_revenue_by_product" => [
        "period" => "Period",
        "product" => "Product",
        "quantity" => "Quantity",
        "total_cost" => "Total Cost",
        "revenue" => "Revenue",
    ],

    "menu_engineering" => [
        "period" => "Period",
        "product" => "Product",
        "quantity" => "Quantity Sold",
        "total_sales" => "Total Sales",
        "gross_profit" => "Gross Profit",
        "margin_percentage" => "Margin %",
        "classification" => "Classification",
        "recommendation" => "Recommendation",
    ],

    "slow_moving_products" => [
        "product" => "Product",
        "quantity" => "Quantity Sold",
        "total_sales" => "Total Sales",
        "last_sold_at" => "Last Sold At",
        "recommendation" => "Recommendation",
    ],

    "loyalty_program_summary" => [
        "period" => "Period",
        "program_name" => "Program Name",
        "total_customers" => "Total Customers",
        "total_earned_points" => "Total Earned Points",
        "total_redeemed_points" => "Total Redeemed Points",
        "total_expired_points" => "Total Expired Points",
    ],

    "loyalty_total_earned_points" => [
        "period" => "Period",
        "program_name" => "Program Name",
        "total_earned_points" => "Total Earned Points",
    ],

    "loyalty_total_redeemed_points" => [
        "period" => "Period",
        "program_name" => "Program Name",
        "total_redeemed_points" => "Total Redeemed Points",
    ],

    "loyalty_total_expired_points" => [
        "period" => "Period",
        "program_name" => "Program Name",
        "total_expired_points" => "Total Expired Points",
    ],

    "loyalty_system_points_balance" => [
        "period" => "Period",
        "program_name" => "Program Name",
        "total_active_customers" => "Total Active Customers",
        "total_points_balance" => "Total Points Balance",
    ],

    "loyalty_redemption_rate" => [
        "period" => "Period",
        "program_name" => "Program Name",
        "earned_points" => "Earned Points",
        "redeemed_points" => "Redeemed Points",
        "redemption_rate" => "Redemption Rate (%)",
    ],

    "loyalty_average_points_per_program" => [
        "period" => "Period",
        "program_name" => "Program Name",
        "total_customers" => "Total Customers",
        "average_points_balance" => "Average Points Balance",
    ],

    "loyalty_points_lifecycle_timeline" => [
        "date" => "Date",
        "earned_points" => "Earned Points",
        "redeemed_points" => "Redeemed Points",
        "expired_points" => "Expired Points",
    ],


    "loyalty_last_activity" => [
        "customer_name" => "Customer Name",
        "last_earned_date" => "Last Earned Date",
        "last_redeemed_date" => "Last Redeemed Date",
        "last_transaction_type" => "Last Transaction Type",
    ],

    "loyalty_inactive_customers" => [
        "customer_name" => "Customer Name",
        "last_activity_date" => "Last Activity Date",
        "days_inactive" => "Days Inactive",
    ],

    "loyalty_no_redemptions" => [
        "customer_name" => "Customer Name",
        "lifetime_points" => "Lifetime Points",
        "total_redemptions" => "Total Redemptions",
    ],

    "loyalty_top_customers_by_points" => [
        "customer_name" => "Customer Name",
        "lifetime_points" => "Lifetime Points",
        "points_balance" => "Current Balance",
    ],

    "loyalty_top_customers_by_spend" => [
        "customer_name" => "Customer Name",
        "total_spend" => "Total Spend",
        "total_orders" => "Total Orders",
    ],

    "loyalty_top_customers_by_orders" => [
        "customer_name" => "Customer Name",
        "total_orders" => "Total Orders",
        "average_order_value" => "Average Order Value",
    ],

    "loyalty_tier_customer_distribution" => [
        "period" => "Period",
        "tier_name" => "Tier Name",
        "customers_count" => "Customers Count",
    ],


    "loyalty_tier_redemption_rate" => [
        "period" => "Period",
        "tier_name" => "Tier Name",
        "earned_points" => "Earned Points",
        "redeemed_points" => "Redeemed Points",
        "redemption_rate" => "Redemption Rate (%)",
    ],

    "loyalty_most_redeemed_rewards" => [
        "period" => "Period",
        "reward_name" => "Reward Name",
        "reward_type" => "Reward Type",
        "total_redemptions" => "Total Redemptions",
        "total_points_spent" => "Total Points Spent",
    ],

    "loyalty_least_used_rewards" => [
        "period" => "Period",
        "reward_name" => "Reward Name",
        "total_redemptions" => "Total Redemptions",
    ],

    "loyalty_never_redeemed_rewards" => [
        "reward_name" => "Reward Name",
        "points_cost" => "Points Cost",
        "created_date" => "Created Date",
    ],

    "loyalty_rewards_by_type" => [
        "reward_type" => "Reward Type",
        "total_rewards" => "Total Rewards",
        "total_redemptions" => "Total Redemptions",
    ],

    "loyalty_rewards_by_tier" => [
        "period" => "Period",
        "tier_name" => "Tier Name",
        "reward_name" => "Reward Name",
        "points_cost" => "Points Cost",
    ],

    "loyalty_rewards_by_program" => [
        "period" => "Period",
        "program_name" => "Program Name",
        "reward_name" => "Reward Name",
        "reward_type" => "Reward Type",
    ],

    "loyalty_available_gifts" => [
        "gift_id" => "Gift ID",
        "customer_name" => "Customer Name",
        "reward_name" => "Reward Name",
        "valid_until" => "Valid Until",
        "valid_from" => "Valid From",
    ],

    "loyalty_used_gifts" => [
        "gift_id" => "Gift ID",
        "customer_name" => "Customer Name",
        "reward_name" => "Reward Name",
        "used_date" => "Used Date",
    ],

    "loyalty_expired_gifts" => [
        "gift_id" => "Gift ID",
        "customer_name" => "Customer Name",
        "reward_name" => "Reward Name",
        "expiration_date" => "Expiration Date",
    ],

    "loyalty_gift_usage_rate" => [
        "reward_name" => "Reward Name",
        "issued_count" => "Issued Count",
        "used_count" => "Used Count",
        "usage_rate" => "Usage Rate (%)",
    ],

    "loyalty_unused_gifts_per_customer" => [
        "customer_name" => "Customer Name",
        "unused_gifts_count" => "Unused Gifts Count",
    ],

    "loyalty_gifts_linked_to_orders" => [
        "order_id" => "Order ID",
        "gift_id" => "Gift ID",
        "reward_name" => "Reward Name",
        "discount_value" => "Discount Value",
    ],

    "loyalty_redemptions_by_status" => [
        "period" => "Period",
        "status" => "Status",
        "total_redemptions" => "Total Redemptions",
        "total_points" => "Total Points",
    ],

    "loyalty_redemptions_by_program" => [
        "period" => "Period",
        "program_name" => "Program Name",
        "total_redemptions" => "Total Redemptions",
        "total_points" => "Total Points",
    ],

    "loyalty_average_points_per_redemption" => [
        "period" => "Period",
        "program_name" => "Program Name",
        "average_points" => "Average Points",
    ],

    "loyalty_active_promotions" => [
        "promotion_name" => "Promotion Name",
        "promotion_type" => "Promotion Type",
        "usage_count" => "Usage Count",
        "start_date" => "Start Date",
        "end_date" => "End Date",
    ],

    "loyalty_expired_promotions" => [
        "promotion_name" => "Promotion Name",
        "promotion_type" => "Promotion Type",
        "end_date" => "End Date",
    ],

    "loyalty_promotion_usage" => [
        "promotion_name" => "Promotion Name",
        "total_usage" => "Total Usage",
        "total_customers" => "Total Customers",
    ],

    "loyalty_highest_impact_promotions" => [
        "promotion_name" => "Promotion Name",
        "total_points_generated" => "Total Points Generated",
    ],

    "loyalty_bonus_vs_multiplier_comparison" => [
        "promotion_type" => "Promotion Type",
        "total_promotions" => "Total Promotions",
        "total_usage" => "Total Usage",
    ],

    "loyalty_category_boost_promotions" => [
        "promotion_name" => "Promotion Name",
        "usage_count" => "Usage Count",
    ],

    "loyalty_new_member_promotions" => [
        "promotion_name" => "Promotion Name",
        "customers_joined" => "Customers Joined",
        "bonus_points" => "Bonus Points",
    ],

    "loyalty_free_items_cost" => [
        "period" => "Period",
        "product_name" => "Product Name",
        "quantity" => "Quantity",
        "cost_price" => "Cost Price",
        "total_cost" => "Total Cost",
    ],

    "loyalty_revenue_from_loyalty_customers" => [
        "period" => "Period",
        "revenue" => "Revenue",
    ],

    "loyalty_revenue_before_after_loyalty" => [
        "period" => "Period",
        "revenue_before" => "Revenue Before",
        "revenue_after" => "Revenue After",
    ],

    "loyalty_average_order_value_loyalty_customers" => [
        "period" => "Period",
        "total_orders" => "Total Orders",
        "average_order_value" => "Average Order Value",
    ],

    "sales_by_cashier" => [
        "date" => "Date",
        "period" => "Period",
        "cashier" => "Cashier",
        "total_orders" => "Total Orders",
        "total_products" => "Total Products",
        "subtotal" => "Subtotal",
        "tax" => "Tax",
        "total" => "Total",
    ],
];
