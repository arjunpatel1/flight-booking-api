<?php

return [
    "loyalty_transactions" => "معاملات الولاء",
    "loyalty_transaction" => "معاملة الولاء",

    "table" => [
        "customer" => "العميل",
        "type" => "النوع",
        "description" => "الوصف",
        "points" => "النقاط",
        "amount" => "المبلغ",
    ],

    "filters" => [
        "customer" => "العميل",
        "type" => "النوع",
    ],

    "type_descriptions" => [
        "default" => [
            "earn" => "تم كسب :points نقطة للطلب رقم #:order_id",
            "redeem" => "تم استبدال :points نقطة في الطلب رقم #:order_id",
            "adjust" => "تعديل يدوي بمقدار :points نقطة",
            "expire" => "انتهت صلاحية :points نقطة بسبب عدم النشاط",
            "bonus" => "تمت إضافة :points نقطة كمكافأة من عرض ترويجي"
        ]
    ]
];
