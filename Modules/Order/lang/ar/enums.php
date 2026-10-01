<?php

return [
    "order_types" => [
        "dine_in" => "الطلبات الداخلية",
        "pick_up" => "استلام من الفرع",
        "takeaway" => "سفري",
        "drive_thru" => "طلب من السيارة",
        "pre_order" => "طلب مسبق",
        "catering" => "خدمات الطعام",
        "self_service" => "خدمة ذاتية",
        "delivery" => "توصيل",
    ],

    "order_statuses" => [
        "pending" => "قيد الانتظار",
        "confirmed" => "تم التأكيد",
        "preparing" => "قيد التحضير",
        "ready" => "جاهز",
        "served" => "تم التقديم",
        "completed" => "مكتمل",
        "cancelled" => "ملغي",
        "refunded" => "مسترد",
        "merged" => "مُدمج",
    ],

    "order_payment_statuses" => [
        "unpaid" => "غير مدفوع",
        "paid" => "مدفوع",
        "partially_paid" => "مدفوع جزئيًا",
    ],

    "reason_types" => [
        "cancellation" => "إلغاء",
        "refund" => "استرداد",
    ],


    "order_product_statuses" => [
        "pending" => "قيد الانتظار",
        "preparing" => "قيد التحضير",
        "ready" => "جاهز",
        "served" => "تم التقديم",
        "cancelled" => "ملغي",
        "refunded" => "تم الاسترجاع",
    ],

    "discount_types" => [
        "discount" => "خصم",
        "voucher" => "قسيمة",
    ],
];
