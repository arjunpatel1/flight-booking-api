<?php

return [
    "orders" => [
        "type" => "النوع",
        "table_id" => "الطاولة",
        "payment_methods" => "طرق الدفع",
        "payment_methods.*" => "طريقة الدفع",
        "payments" => "المدفوعات",
        "payments.*.method" => "طريقة الدفع",
        "payments.*.amount" => "المبلغ",
        "pos_register_id" => "سجل نقطة البيع",
        "products" => "المنتجات",
        "products.*.id" => "معرّف المنتج",
        "products.*.quantity" => "كمية المنتج",
        "products.*.options" => "الخيارات",
        "products.*.options.*.id" => "معرّف الخيار",
        "products.*.options.*.values" => "قيم الخيار",
        "products.*.options.*.values.*.id" => "معرّف قيمة الخيار",
        "products.*.options.*.values.*.value" => "قيمة الخيار",
        "notes" => "ملاحظات",
        "guest_count" => "عدد الضيوف",
        "payment_type" => "نوع الدفع",
        "amount_to_be_paid" => "المبلغ المراد دفعه",
        "car_plate" => "رقم لوحة السيارة",
        "car_description" => "وصف السيارة",
        "products.*.actions" => "الإجراءات",
        "products.*.actions.*.action" => "الإجراء",
        "products.*.actions.*.quantity" => "الكمية",
        "refund_payment_method" => "طريقة استرجاع الدفع",
        "products.*.order_product_id" => "منتج الطلب",
    ],

    "reasons" => [
        "name" => "الاسم",
        "type" => "النوع",
        "is_active" => "نشط",
    ],

    "cancel_or_refund" => [
        "register_id" => "سجل نقطة البيع",
        "reason_id" => "السبب",
        "note" => "ملاحظة",
        "session_id" => "الجلسة",
        "refund_payment_method" => "طريقة استرداد الدفع",
    ],

    "payments" => [
        "register_id" => "سجل نقطة البيع",
        "payment_mode" => "طريقة الدفع",
        "amount_to_be_paid" => "المبلغ المطلوب دفعه",
        "customer_given_amount" => "المبلغ المدفوع من العميل",
        "change_return" => "المبلغ المرتجع",
        "payments" => "المدفوعات",
        "payments.*.method" => "طريقة الدفع",
        "payments.*.amount" => "المبلغ",
        "payments.*.transaction_id" => "رقم العملية",
        "payments.*.gateway" => "بوابة الدفع",
        "payments.*.gateway_data.terminal_id" => "الجهاز",
        "payments.*.gateway_data.payment_id" => "معرف الدفع في البوابة",
        "payments.*.gateway_data.gateway_payment_id" => "معرف الدفع في البوابة",
        "payments.*.gateway_data.razorpay_payment_id" => "معرف دفع Razorpay",
        "payments.*.gateway_data.razorpay_order_id" => "معرف طلب Razorpay",
        "payments.*.gateway_data.razorpay_signature" => "توقيع Razorpay",
    ],

    "feedback" => [
        "order_id" => "الطلب",
        "order_reference" => "مرجع الطلب",
        "rating" => "التقييم",
        "tags" => "الوسوم",
        "tags.*" => "الوسم",
        "comment" => "التعليق",
        "source" => "المصدر",
    ],

    "print" => [
        "register_id" => "جهاز نقطة البيع",
        "branch_id" => "الفرع",
    ]

];
