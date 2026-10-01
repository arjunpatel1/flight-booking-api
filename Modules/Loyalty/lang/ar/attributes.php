<?php

return [
    "loyalty_programs" => [
        "name" => "الاسم",
        "earning_rate" => "معدل الكسب",
        "redemption_rate" => "معدل الاستبدال",
        "min_redeem_points" => "الحد الأدنى لنقاط الاستبدال",
        "points_expire_after" => "انتهاء صلاحية النقاط بعد",
        "is_active" => "نشط",
    ],

    "loyalty_tiers" => [
        "name" => "الاسم",
        "loyalty_program_id" => "البرنامج",
        "min_spend" => "الحد الأدنى للإنفاق",
        "multiplier" => "المضاعف",
        "benefits" => "المزايا",
        "order" => "الترتيب",
        "is_active" => "نشط",
        "icon" => "الأيقونة",
    ],

    "loyalty_rewards" => [
        "name" => "الاسم",
        "description" => "الوصف",
        "loyalty_program_id" => "البرنامج",
        "loyalty_tier_id" => "الدرجة",
        "type" => "النوع",
        "points_cost" => "تكلفة النقاط",
        "value" => "القيمة",
        "value_type" => "نوع القيمة",
        "max_redemptions_per_order" => "الحد الأقصى لمرات الاستبدال لكل طلب",
        "usage_limit" => "حد الاستخدام",
        "per_customer_limit" => "حد لكل عميل",
        "conditions" => "الشروط",
        "conditions.min_spend" => "الحد الأدنى للإنفاق",
        "conditions.branch_ids" => "الفروع",
        "conditions.branch_ids.*" => "الفرع",
        "conditions.available_days" => "الأيام المتاحة",
        "conditions.available_days.*" => "اليوم المتاح",
        "starts_at" => "يبدأ في",
        "ends_at" => "ينتهي في",
        "is_active" => "نشط",
        "files.icon" => "الأيقونة",
        "meta" => "البيانات الإضافية",
        "meta.discount_type" => "نوع الخصم",
        "meta.value" => "قيمة الخصم",
        "meta.min_order_total" => "الحد الأدنى لقيمة الطلب",
        "meta.max_order_total" => "الحد الأقصى لقيمة الطلب",
        "meta.max_discount" => "الحد الأقصى للخصم",
        "meta.expires_in_days" => "تنتهي الصلاحية بعد (أيام)",
        "meta.code_prefix" => "بادئة الكود",
        "meta.product_sku" => "المنتج",
        "meta.quantity" => "الكمية",
        "meta.target_tier" => "الدرجة المستهدفة",
    ],

    "loyalty_promotions" => [
        "name" => "الاسم",
        "description" => "الوصف",
        "loyalty_program_id" => "البرنامج",
        "type" => "النوع",
        "usage_limit" => "حد الاستخدام",
        "per_customer_limit" => "حد لكل عميل",
        "conditions" => "الشروط",
        "conditions.min_spend" => "الحد الأدنى للإنفاق",
        "conditions.branch_ids" => "الفروع",
        "conditions.branch_ids.*" => "الفرع",
        "conditions.available_days" => "الأيام المتاحة",
        "conditions.available_days.*" => "اليوم المتاح",
        "multiplier" => "المضاعف",
        "bonus_points" => "نقاط إضافية",
        "conditions.valid_days" => "أيام الصلاحية",
        "starts_at" => "يبدأ في",
        "ends_at" => "ينتهي في",
        "is_active" => "نشط",
        'conditions.categories' => 'الفئات',
        'conditions.categories.*' => 'الفئة',
    ],

    "get_rewards" => [
        "customer_id" => "العميل",
    ],

    "redeem_rewards" => [
        "customer_id" => "العميل",
    ],

    "available_gifts" => [
        "customer_id" => "العميل",
    ],

];
