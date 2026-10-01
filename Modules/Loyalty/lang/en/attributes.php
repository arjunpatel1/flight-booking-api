<?php

return [
    "adjust_points" => [
        "points" => "Points",
        "reason" => "Reason",
    ],

    "loyalty_programs" => [
        "name" => "Name",
        "earning_rate" => "Earning Rate",
        "redemption_rate" => "Redemption Rate",
        "min_redeem_points" => "Minimum Redeem Points",
        "points_expire_after" => "Points Expire After",
        "is_active" => "Active",
    ],

    "loyalty_tiers" => [
        "name" => 'Name',
        "loyalty_program_id" => "Program",
        "min_spend" => "Minimum Spend",
        "multiplier" => "Multiplier",
        "benefits" => "Benefits",
        "order" => "Order",
        "is_active" => "Active",
        "icon" => "Icon",
    ],

    "loyalty_rewards" => [
        "name" => "Name",
        "description" => "Description",
        "loyalty_program_id" => "Program",
        "loyalty_tier_id" => "Tier",
        "type" => "Type",
        "points_cost" => "Points Cost",
        "value" => "Value",
        "value_type" => "Value Type",
        "max_redemptions_per_order" => "Maximum Redemptions Per Order",
        "usage_limit" => "Usage Limit",
        "per_customer_limit" => "Per Customer Limit",
        "conditions" => "Conditions",
        "conditions.min_spend" => "Minimum Spend",
        "conditions.branch_ids" => "Branches",
        "conditions.branch_ids.*" => "Branch",
        "conditions.available_days" => "Available Days",
        "conditions.available_days.*" => "Available Day",
        "starts_at" => "Start At",
        "ends_at" => "End At",
        "is_active" => "Active",
        "files.icon" => "Icon",
        "meta" => "Meta",
        "meta.discount_type" => "Discount Type",
        "meta.value" => "Discount Value",
        "meta.min_order_total" => "Minimum Order Total",
        "meta.max_order_total" => "Maximum Order Total",
        "meta.max_discount" => "Maximum Discount",
        "meta.expires_in_days" => "Expires In Days",
        "meta.code_prefix" => "Code Prefix",
        "meta.product_sku" => "Product",
        "meta.quantity" => "Quantity",
        "meta.target_tier" => "Target Tier",
    ],

    "loyalty_promotions" => [
        "name" => "Name",
        "description" => "Description",
        "loyalty_program_id" => "Program",
        "type" => "Type",
        "usage_limit" => "Usage Limit",
        "per_customer_limit" => "Per Customer Limit",
        "conditions" => "Conditions",
        "conditions.min_spend" => "Minimum Spend",
        "conditions.branch_ids" => "Branches",
        "conditions.branch_ids.*" => "Branch",
        "conditions.available_days" => "Available Days",
        "conditions.available_days.*" => "Available Day",
        "multiplier" => "Multiplier",
        "bonus_points" => "Bonus Points",
        "conditions.valid_days" => "Valid Days",
        "starts_at" => "Start At",
        "ends_at" => "End At",
        "is_active" => "Active",
        'conditions.categories' => 'Categories',
        'conditions.categories.*' => 'Category',
    ],

    "get_rewards" => [
        "customer_id" => "Customer",
    ],

    "redeem_rewards" => [
        "customer_id" => "Customer",
    ],

    "available_gifts" => [
        "customer_id" => "Customer",
    ],

];
