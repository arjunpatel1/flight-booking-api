<?php

return [
    'tenants' => [
        'name' => 'الاسم',
        'legal_name' => 'الاسم القانوني',
        'slug' => 'المعرف',
        'domain' => 'النطاق',
        'contact_name' => 'اسم جهة الاتصال',
        'contact_email' => 'بريد جهة الاتصال',
        'contact_phone' => 'هاتف جهة الاتصال',
        'settings' => 'الإعدادات',
        'active_plan' => 'الخطة النشطة',
        'is_active' => 'نشط',
    ],
    'subscription_plans' => [
        'name' => 'الاسم',
        'code' => 'الكود',
        'description' => 'الوصف',
        'billing_cycle' => 'دورة الفوترة',
        'price' => 'السعر',
        'currency' => 'العملة',
        'features' => 'الميزات',
        'limits' => 'الحدود',
        'is_active' => 'نشط',
    ],
    'tenant_subscriptions' => [
        'tenant_id' => 'المستأجر',
        'subscription_plan_id' => 'الخطة',
        'status' => 'الحالة',
        'starts_at' => 'تاريخ البدء',
        'ends_at' => 'تاريخ الانتهاء',
        'trial_ends_at' => 'نهاية التجربة',
        'cancelled_at' => 'تاريخ الإلغاء',
        'overrides' => 'التجاوزات',
    ],
];
