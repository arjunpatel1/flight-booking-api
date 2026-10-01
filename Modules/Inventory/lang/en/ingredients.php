<?php
return [
    "ingredients" => "Ingredients",
    "ingredient" => "Ingredient",

    "table" => [
        "name" => "Name",
        "unit" => "Unit",
        "current_stock" => "Current Stock",
        "cost_per_unit" => "Cost Per Unit",
        "alert_quantity" => "Alert Quantity",
    ],

    "form" => [
        "cards" => [
            "ingredient_information" => "Ingredient Information",
        ]
    ],

    "filters" => [
        "unit" => "Unit",
    ],

    "tooltips" => [
        "current_stock_is_above_the_alert_threshold" => "Current stock is above the alert threshold.",
        "current_stock_has_fallen_below_the_alert_threshold" => "Current stock has fallen below the alert threshold."
    ],

    "notifications" => [
        "low_stock" => [
            "title" => ":ingredient stock is low",
            "message" => "Current stock is :current. Alert quantity is :alert.",
            "command_result" => ":count low-stock notification(s) created.",
        ],
    ],
];
