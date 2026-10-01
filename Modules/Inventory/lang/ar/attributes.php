<?php

return [
    "suppliers" => [
        "name" => "الاسم",
        "phone" => "رقم الهاتف",
        "email" => "البريد الإلكتروني",
        "address" => "العنوان",
        "branch_id" => "الفرع",
    ],

    "units" => [
        "name" => "الاسم",
        "symbol" => "الرمز",
        "type" => "النوع",
    ],

    "ingredients" => [
        "name" => "الاسم",
        "branch_id" => "الفرع",
        "unit_id" => "الوحدة",
        "cost_per_unit" => "التكلفة لكل وحدة",
        "alert_quantity" => "كمية التنبيه",
        "is_returnable" => "قابل للإرجاع",
    ],

    "stock_movements" => [
        "branch_id" => "الفرع",
        "ingredient_id" => 'المكوّن',
        "supplier_id" => "المورد",
        "type" => "النوع",
        "quantity" => "الكمية",
        "note" => "ملاحظة",
    ],

    "purchases" => [
        "branch_id" => "الفرع",
        "supplier_id" => "المورد",
        "notes" => "ملاحظات",
        "expected_at" => "تاريخ الاستلام المتوقع",
        "discount" => "الخصم",
        "tax" => "الضريبة",
        "items" => "العناصر",
        "items.*" => "عنصر",
        "items.*.ingredient_id" => "المكوّن",
        "items.*.quantity" => "الكمية",
        "items.*.received_quantity" => "الكمية المستلمة",
        "items.*.unit_cost" => "تكلفة الوحدة",
        "items.*.id" => "المعرّف",
        "reference" => "رقم المرجع",
    ]
];
