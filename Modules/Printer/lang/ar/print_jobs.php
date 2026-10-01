<?php

return [
    'print_jobs' => 'مهام الطباعة',
    'print_job' => 'مهمة طباعة',
    'retry' => 'إعادة المحاولة',
    'retry_confirmation' => 'هل تريد إعادة محاولة مهمة الطباعة هذه؟',
    'success_jobs_cannot_be_retried' => 'لا يمكن إعادة محاولة مهام الطباعة الناجحة.',

    'summary' => [
        'total' => 'إجمالي المهام',
        'pending' => 'قيد الانتظار',
        'success' => 'تمت الطباعة',
        'failed' => 'فشلت',
    ],

    'diagnostics' => [
        'title' => 'تشخيص الطابعات',
        'active_printers' => 'الطابعات النشطة',
        'online_agents' => 'الوكلاء المتصلون',
        'offline_agents' => 'الوكلاء غير المتصلين',
        'cash_drawer' => 'درج النقد',
        'barcode_scanner' => 'ماسح الباركود',
        'weighing_scale' => 'ميزان الوزن',
        'failed_last_24h' => 'فشل خلال 24 ساعة',
        'ready' => 'جاهز',
        'needs_setup' => 'يحتاج إعداد',
    ],

    'table' => [
        'branch' => 'الفرع',
        'order' => 'الطلب',
        'print_type' => 'الطباعة',
        'printer_type' => 'النوع',
        'printer_name' => 'الطابعة',
        'agent' => 'الوكيل',
        'pipeline' => 'مسار الطباعة',
        'paper_size' => 'الورق',
        'copies' => 'النسخ',
        'status' => 'الحالة',
        'error_message' => 'الخطأ',
        'completed_at' => 'وقت الإكمال',
    ],

    'pipeline' => [
        'completed' => 'مكتمل',
        'in_progress' => 'قيد التنفيذ',
        'warning' => 'تحذير',
        'failed' => 'فشل',
    ],

    'steps' => [
        'order' => 'محتوى الطلب',
        'route' => 'مسار الطابعة',
        'agent' => 'الوكيل المستهدف',
        'queue' => 'سجل قائمة الانتظار',
        'delivery' => 'تسليم الوكيل',
    ],

    'tracking' => [
        'events' => 'التتبع',
        'route_source' => 'مصدر التوجيه',
        'claimed_by' => 'تم الحجز بواسطة',
        'lease_until' => 'الإيجار حتى',
    ],

    'stages' => [
        'queued' => 'في قائمة الانتظار',
        'retry_queued' => 'أُعيد إلى قائمة الانتظار',
        'claimed' => 'تم الحجز',
        'success' => 'تمت الطباعة',
        'failed' => 'فشل',
        'printer_config' => 'تم تكوين الطابعة',
        'no_printer' => 'لا توجد طابعة',
        'no_printable_products' => 'لا شيء للطباعة',
        'no_kitchen_printer' => 'لا توجد طابعة مطبخ',
    ],

    'messages' => [
        'check_order_ok' => 'تم تحديد الطلب رقم :ref.',
        'route_resolved' => 'تم تحديد مسار الطابعة.',
        'route_missing' => 'لم يتم العثور على مسار طابعة.',
        'route_no_match' => 'لم يتطابق أي مسار طابعة مع التعيين أو نقطة البيع أو الفرع.',
        'agent_selected' => 'تم اختيار الوكيل :agent.',
        'agent_missing' => 'لا يوجد وكيل محدد لهذه المهمة.',
        'agent_none' => 'لم يتم تحديد وكيل متصل صريح.',
        'delivery_success' => 'أبلغ الوكيل عن نجاح الطباعة.',
        'delivery_failed' => 'أبلغ الوكيل عن فشل الطباعة.',
        'delivery_claimed' => 'تم الحجز بواسطة :agent؛ في انتظار التقرير.',
        'delivery_waiting_agent' => 'في انتظار استلام الوكيل :agent.',
        'delivery_waiting_any' => 'في انتظار استلام أي وكيل متوافق.',
    ],

    'live' => [
        'label' => 'تحديثات مباشرة',
        'on' => 'مباشر',
        'off' => 'متوقف',
    ],

    'filters' => [
        'status' => 'الحالة',
    ],
];
