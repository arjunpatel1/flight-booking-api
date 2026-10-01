<?php

return [
    "ingredients" => "المكونات",
    "ingredient" => "المكوّن",

    "table" => [
        "name" => "الاسم",
        "unit" => "الوحدة",
        "current_stock" => "المخزون الحالي",
        "cost_per_unit" => "التكلفة لكل وحدة",
        "alert_quantity" => "كمية التنبيه",
    ],

    "form" => [
        "cards" => [
            "ingredient_information" => "معلومات المكوّن",
        ]
    ],

    "filters" => [
        "unit" => "الوحدة",
    ],

    "tooltips" => [
        "current_stock_is_above_the_alert_threshold" => "المخزون الحالي أعلى من كمية التنبيه.",
        "current_stock_has_fallen_below_the_alert_threshold" => "المخزون الحالي أقل من كمية التنبيه.",
    ],

    "notifications" => [
        "low_stock" => [
            "title" => "مخزون :ingredient منخفض",
            "message" => "المخزون الحالي :current. كمية التنبيه :alert.",
            "command_result" => "تم إنشاء :count تنبيه/تنبيهات للمخزون المنخفض.",
        ],
    ],
];
