<?php

return [
    "suppliers" => [
        "name" => "Name",
        "phone" => "Phone",
        "email" => "Email",
        "address" => "Address",
        "branch_id" => "Branch",
    ],

    "units" => [
        "name" => "Name",
        "symbol" => "Symbol",
        "type" => "Type",
    ],

    "ingredients" => [
        "name" => "Name",
        "branch_id" => "Branch",
        "unit_id" => "Unit",
        "cost_per_unit" => "Cost Per Unit",
        "alert_quantity" => "Alert Quantity",
        "is_returnable" => "Returnable",
    ],

    "stock_movements" => [
        "branch_id" => "Branch",
        "ingredient_id" => 'Ingredient',
        "supplier_id" => "Supplier",
        "type" => "Type",
        "quantity" => "Quantity",
        "note" => "Note",
    ],

    "purchases" => [
        "branch_id" => "Branch",
        "supplier_id" => "Supplier",
        "notes" => "Notes",
        "expected_at" => "Expected Date",
        "discount" => "Discount",
        "tax" => "Tax",
        "items" => "Items",
        "items.*" => "Item",
        "items.*.ingredient_id" => "Ingredient",
        "items.*.quantity" => "Quantity",
        "items.*.received_quantity" => "Received Quantity",
        "items.*.unit_cost" => "Unit Cost",
        "items.*.id" => "ID",
        "reference" => "Reference No",
    ]
];
