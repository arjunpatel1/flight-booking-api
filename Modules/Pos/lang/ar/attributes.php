<?php

return [
    "pos_registers" => [
        "name" => "الاسم",
        "branch_id" => "الفرع",
        "code" => "الرمز",
        "note" => "ملاحظة",
        "is_active" => "نشط",
        "invoice_printer_id" => "طابعة الفواتير",
        "bill_printer_id" => "طابعة الإيصال",
        "waiter_printer_id" => "طابعة النادل",
        "delivery_printer_id" => "طابعة التوصيل",
    ],

    "pos_sessions" => [
        "branch_id" => "الفرع",
        "pos_register_id" => "سجل نقطة البيع",
        "opening_float" => "الرصيد الافتتاحي",
        "notes" => "ملاحظات",
        "declared_cash" => "النقد المصرح به",
    ],

    "pos_cash_movements" => [
        "pos_register_id" => "سجل نقطة البيع",
        "reference" => "المرجع",
        "amount" => "المبلغ",
        "notes" => "ملاحظات",
        "reason" => "السبب",
        "direction" => "الاتجاه",
    ],

    "kitchen_stations" => [
        "name" => "اسم المحطة",
        "description" => "الوصف",
        "branch_id" => "الفرع",
        "display_order" => "ترتيب العرض",
        "is_active" => "نشط",
        "prep_time_minutes" => "وقت التحضير الافتراضي",
        "max_concurrent_items" => "الحد الأقصى للعناصر المتزامنة",
        "color" => "اللون",
        "sound_enabled" => "الصوت مفعل",
        "auto_bump_minutes" => "دقائق الترحيل التلقائي",
        "categories" => "الأقسام",
    ]
];
