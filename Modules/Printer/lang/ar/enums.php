<?php
return [
    "printer_connection_types" => [
        "tcp" => "طابعة حرارية عبر الشبكة (TCP/IP)",
        "spooler" => "طابعة USB / طابعة النظام (Spooler)",
        "usb_raw" => "طابعة USB خام ESC/POS (متقدم)",
        "bluetooth" => "طابعة حرارية عبر البلوتوث"
    ],

    "printer_paper_sizes" => [
        "58mm" => "58 مم",
        "80mm" => "80 مم",
    ],

    "printer_spooler_orientations" => [
        "portrait" => "عمودي",
        "landscape" => "أفقي"
    ],

    "printer_usb_raw_endpoints" => [
        "0x01" => "إخراج"
    ],

    "print_job_statuses" => [
        "pending" => "قيد الانتظار",
        "success" => "تم بنجاح",
        "failed" => "فشل",
    ],

    "printer_spooler_color_modes" => [
        "color" => "ملون",
        "mono" => "أحادي اللون",
    ],

    "printer_spooler_color_sides" => [
        "one-sided" => "وجه واحد",
        "two-sided-long-edge" => "على الوجهين (الحافة الطويلة)",
        "two-sided-short-edge" => "على الوجهين (الحافة القصيرة)",
    ],

    "print_content_types" => [
        "invoice" => "فاتورة",
        "bill" => "حساب",
        "kitchen" => "المطبخ",
        "waiter" => "النادل",
        "delivery" => "التوصيل",
    ]
];
