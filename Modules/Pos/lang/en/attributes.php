<?php

return [
    "pos_registers" => [
        "name" => "Name",
        "branch_id" => "Branch",
        "code" => "Code",
        "note" => "Note",
        "is_active" => "Active",
        "invoice_printer_id" => "Invoice Printer",
        "bill_printer_id" => "Bill Printer",
        "waiter_printer_id" => "Waiter Printer",
        "delivery_printer_id" => "Delivery Printer",
    ],

    "pos_sessions" => [
        "branch_id" => "Branch",
        "pos_register_id" => "Pos Register",
        "opening_float" => "Opening Float",
        "notes" => "Notes",
        "declared_cash" => "Declared Cash",
    ],

    "pos_cash_movements" => [
        "pos_register_id" => "Pos Register",
        "reference" => "Reference",
        "amount" => "Amount",
        "notes" => "Notes",
        "reason" => "Reason",
        "direction" => "Direction",
    ],

    "kitchen_stations" => [
        "name" => "Station Name",
        "description" => "Description",
        "branch_id" => "Branch",
        "display_order" => "Display Order",
        "is_active" => "Active",
        "prep_time_minutes" => "Default Prep Time",
        "max_concurrent_items" => "Max Concurrent Items",
        "color" => "Color",
        "sound_enabled" => "Sound Enabled",
        "auto_bump_minutes" => "Auto Bump Minutes",
        "categories" => "Categories",
    ]
];
