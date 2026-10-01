<?php

return [
    "printers" => "الطابعات",
    "printer" => "الطابعة",
    "printer_assignments" => "تعيينات الطابعات",

    "table" => [
        "name" => "الاسم",
        "connection_type" => "نوع الاتصال",
    ],

    "form" => [
        "cards" => [
            "printer_information" => "معلومات الطابعة",
            "connection_options" => "خيارات الاتصال",
        ],
        "no_configuration_available" => "لا تتوفر إعدادات لنوع الاتصال المحدد"
    ],

    "filters" => [
        "connection_type" => "نوع الاتصال",
    ],

    "assignments" => [
        "sections" => [
            "printer_master" => "قائمة الطابعات",
            "print_type_mapping" => "ربط أنواع الطباعة",
            "user_role_mapping" => "ربط المستخدمين والأدوار بالطابعات",
            "default_fallback" => "الطابعة الافتراضية الاحتياطية",
        ],
        "labels" => [
            "default_printer" => "الطابعة الافتراضية",
            "print_type" => "نوع الطباعة",
            "printer" => "الطابعة",
            "user" => "المستخدم",
            "role" => "الدور",
            "user_mappings" => "تعيينات المستخدمين",
            "role_mappings" => "تعيينات الأدوار",
        ],
        "actions" => [
            "add_user_mapping" => "إضافة تعيين مستخدم",
            "add_role_mapping" => "إضافة تعيين دور",
        ],
        "messages" => [
            "select_branch" => "اختر فرعاً لإدارة تعيينات الطابعات.",
        ],
    ],

    "printer_connection_types_description" => [
        "tcp" => "طابعة حرارية متصلة عبر الشبكة (موصى بها للمطابخ)",
        "spooler" => "طابعة متصلة عبر تعريف نظام التشغيل (Windows / macOS / Linux)",
        "usb_raw" => "اتصال USB مباشر بدون تعريف نظام التشغيل (للاستخدام المتقدم فقط)",
        "bluetooth" => "طابعة حرارية متصلة عبر جهاز بلوتوث تسلسلي RFCOMM"
    ]
];
