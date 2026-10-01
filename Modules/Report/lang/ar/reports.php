<?php
return [
    'filtered_records' => 'السجلات المصفاة',
    'filtered_quantity' => 'إجمالي الكمية',
    'filtered_value' => 'إجمالي القيمة',
    'gross_collected' => 'إجمالي المبالغ المحصلة',
    'refunds' => 'المبالغ المستردة',
    'net_collected' => 'صافي المبالغ المحصلة',
    "reports" => "التقارير",
    "report" => "تقرير",
    "saved_filter" => "عامل تصفية تقرير محفوظ",
    "unassigned" => "غير معين",

    "stock_status" => [
        "healthy" => "صحي",
        "low_stock" => "مخزون منخفض",
        "out_of_stock" => "نفد المخزون",
    ],

    "filters" => [
        "start_date" => "تاريخ البداية",
        "end_date" => "تاريخ النهاية",
        "order_status" => "حالة الطلب",
        "order_type" => "نوع الطلب",
        "payment_status" => "حالة الدفع",
        "product_name" => "اسم المنتج",
        "payment_method" => "طريقة الدفع",
        "provider" => "المزود",
        "reason" => "السبب",
        "direction" => "الاتجاه",
        "menu" => "القائمة",
        "discount_type" => "نوع الخصم",
        "waiter" => "النادل",
        "order_source" => "مصدر الطلب",
        "payment_channel" => "قناة الدفع",
    ],

    "groups" => [
        "restaurant_sales_reports" => "تقارير مبيعات المطعم",
        "inventory_reports" => "تقارير المخزون",
        "pos_reports" => "تقارير نقطة البيع",
        "system_reports" => "تقارير النظام",
        "loyalty_overview_reports" => "تقارير ملخص الولاء",
        "loyalty_customer_reports" => "تقارير عملاء الولاء",
        "loyalty_tier_reports" => "تقارير مستويات الولاء",
        "loyalty_rewards_reports" => "تقارير مكافآت الولاء",
        "loyalty_gifts_reports" => "تقارير مكافآت الولاء",
        "loyalty_redemptions_reports" => "تقارير استبدال نقاط الولاء",
        "loyalty_promotions_reports" => "تقارير عروض الولاء",
        "loyalty_financial_roi_reports" => "تقارير العائد المالي والاستثمار لبرامج الولاء"
    ],

    "definitions" => [
        "sales" => [
            "title" => "تقرير المبيعات",
            "description" => "تفصيل مفصل لأداء المبيعات خلال فترة زمنية محددة.",
        ],

        "products_purchase" => [
            "title" => "تقرير مشتريات المنتجات",
            "description" => "يساعد هذا التقرير في تحليل أداء شراء المنتجات عبر فترات زمنية مختلفة."
        ],

        "tax" => [
            "title" => "تقرير الضرائب",
            "description" => "يلخص الضرائب المحصلة حسب حالة الطلب ونوعه والدفع خلال فترة محددة."
        ],

        "branch_performance" => [
            "title" => "تقرير أداء الفروع",
            "description" => "يعرض مؤشرات رئيسية للمبيعات والطلبات لكل فرع لمقارنة الأداء عبر الزمن."
        ],

        "aggregator_payout_reconciliation" => [
            "title" => "مطابقة دفعات المجمعات",
            "description" => "يقارن مبيعات المجمعات والمرتجعات والمدفوعات المستلمة والمبالغ المعلقة حسب المزود.",
        ],

        "finance_reconciliation" => [
            "title" => "مطابقة مالية",
            "description" => "يجمع المبيعات والضريبة والمدفوعات والمرتجعات والمصروفات والتدفق النقدي ومستحقات المجمعات حسب الفرع.",
        ],

        "payments" => [
            "title" => "تقرير المدفوعات",
            "description" => "يفصل المدفوعات المستلمة حسب الطريقة لتتبع الإيرادات."
        ],

        "discounts_and_vouchers" => [
            "title" => "تقرير الخصومات والقسائم",
            "description" => "يعرض استخدام الخصومات والقسائم، بما في ذلك عدد مرات الاستخدام وإجمالي المبالغ المخفضة.",
        ],

        "product_tax" => [
            "title" => "تقرير ضرائب المنتجات",
            "description" => "يعرض إجمالي الضرائب المطبقة على منتجات محددة خلال فترة معينة."
        ],

        "ingredient_usage" => [
            "title" => "تقرير استخدام المكونات",
            "description" => "يتتبع الكمية الإجمالية لكل مكون تم استخدامه بناءً على المنتجات المباعة خلال الفترة المحددة."
        ],

        "low_stock_alerts" => [
            "title" => "تنبيهات انخفاض المخزون",
            "description" => "يحدد المكونات التي أوشكت على النفاد أو نفدت بالفعل لتنبيهك بإعادة التخزين في الوقت المناسب."
        ],

        "stock_valuation" => [
            "title" => "تقرير تقييم المخزون",
            "description" => "يعرض المخزون الحالي وتكلفة الوحدة وقيمة المخزون وحالة انخفاض المخزون لكل مكون.",
        ],

        "wastage_cost" => [
            "title" => "تقرير تكلفة الهدر",
            "description" => "يتتبع كمية المكونات المهدرة والأسباب وتأثير التكلفة خلال الفترة المحددة.",
        ],

        "register_summary" => [
            "title" => "تقرير ملخص السجل",
            "description" => "يوفر ملخصًا ماليًا لسجلات نقطة البيع، بما في ذلك الجلسات والمبيعات والحركات النقدية والأرصدة."
        ],

        "cash_movement" => [
            "title" => "تقرير الحركات النقدية",
            "description" => "يتتبع جميع الحركات النقدية الداخلة والخارجة في سجلات نقطة البيع، بما في ذلك المستخدمين والأسباب."
        ],

        "sales_by_creator" => [
            "title" => "تقرير المبيعات حسب المُنشئ",
            "description" => "يعرض إجمالي المبيعات حسب المستخدم الذي أنشأ كل طلب، مما يساعد على تتبع أداء الموظفين الفردي."
        ],

        "sales_by_cashier" => [
            "title" => "تقرير المبيعات حسب الكاشير",
            "description" => "يلخص إجمالي المبيعات التي تم التعامل معها من قبل كل كاشير، باستثناء الطلبات الملغاة والمُستردة."
        ],

        "sales_by_waiter" => [
            "title" => "تقرير المبيعات حسب النادل",
            "description" => "يتتبع أداء النادل حسب الطلبات والضيوف والطاولات المعينة والمنتجات والإيرادات.",
        ],

        "waiter_collection" => [
            "title" => "تقرير تحصيل النادل",
            "description" => "يتتبع تحصيلات النادل حسب طريقة الدفع والإكراميات والمبالغ المعلقة.",
        ],

        "peak_hour_sales" => [
            "title" => "تقرير ساعات الذروة",
            "description" => "يعرض أكثر ساعات البيع نشاطاً حسب عدد الطلبات والمنتجات والمبيعات ومتوسط قيمة الطلب.",
        ],

        "categorized_products" => [
            "title" => "تقرير المنتجات المصنفة",
            "description" => "يعرض كل فئة مع إجمالي عدد منتجاتها — مثالي لمراجعة توزيع المنتجات.",
        ],
        "upcoming_orders" => [
            "title" => "تقرير الطلبات القادمة",
            "description" => "يعرض الطلبات المجدولة التي سيتم تحضيرها أو تقديمها في وقت لاحق.",
        ],

        "menu_engineering" => [
            "title" => "تقرير هندسة القائمة",
            "description" => "يصنف المنتجات حسب حجم المبيعات والهامش لتحديد المنتجات القوية والمنتجات التي تحتاج لتحسين.",
        ],

        "slow_moving_products" => [
            "title" => "تقرير المنتجات بطيئة الحركة",
            "description" => "يعرض المنتجات ذات كمية المبيعات المنخفضة خلال الفترة المحددة لتسهيل قرارات تحسين القائمة.",
        ],

        "loyalty_program_summary" => [
            "title" => "ملخص برامج الولاء",
            "description" => "نظرة عامة على كل برنامج ولاء مع عدد العملاء وإجماليات دورة حياة النقاط.",
        ],

        "loyalty_total_earned_points" => [
            "title" => "إجمالي النقاط المكتسبة",
            "description" => "إجمالي نقاط الولاء المكتسبة خلال الفترة المحددة.",
        ],

        "loyalty_total_redeemed_points" => [
            "title" => "إجمالي النقاط المُستبدلة",
            "description" => "إجمالي نقاط الولاء التي تم استبدالها خلال الفترة المحددة.",
        ],

        "loyalty_total_expired_points" => [
            "title" => "إجمالي النقاط المنتهية",
            "description" => "إجمالي نقاط الولاء التي انتهت صلاحيتها دون استبدال.",
        ],

        "loyalty_system_points_balance" => [
            "title" => "رصيد النقاط في النظام",
            "description" => "إجمالي رصيد النقاط الحالي عبر عملاء الولاء النشطين.",
        ],

        "loyalty_redemption_rate" => [
            "title" => "معدل الاستبدال",
            "description" => "نسبة النقاط المُستبدلة إلى المكتسبة لكل برنامج.",
        ],

        "loyalty_average_points_per_program" => [
            "title" => "متوسط النقاط لكل برنامج",
            "description" => "متوسط رصيد النقاط للعملاء في كل برنامج.",
        ],

        "loyalty_points_lifecycle_timeline" => [
            "title" => "الخط الزمني لدورة حياة النقاط",
            "description" => "النقاط المكتسبة مقابل المستبدلة مقابل المنتهية يومياً عبر برامج الولاء.",
        ],

        "loyalty_last_activity" => [
            "title" => "آخر نشاط ولاء",
            "description" => "آخر نشاط ولاء لكل عميل مع تواريخ الكسب/الاستبدال الأخيرة.",
        ],

        "loyalty_inactive_customers" => [
            "title" => "عملاء ولاء غير نشطين",
            "description" => "العملاء الذين ليس لديهم نشاط ولاء حديث ومدة عدم النشاط.",
        ],

        "loyalty_no_redemptions" => [
            "title" => "عملاء بلا استبدالات",
            "description" => "العملاء الذين لم يستبدلوا نقاط الولاء مطلقاً.",
        ],

        "loyalty_top_customers_by_points" => [
            "title" => "أعلى العملاء بالنقاط",
            "description" => "تصنيف العملاء حسب النقاط مدى الحياة والرصيد الحالي.",
        ],

        "loyalty_top_customers_by_spend" => [
            "title" => "أعلى العملاء بالإنفاق",
            "description" => "تصنيف عملاء الولاء حسب إجمالي الإنفاق والطلبات.",
        ],

        "loyalty_top_customers_by_orders" => [
            "title" => "أعلى العملاء بالطلبات",
            "description" => "تصنيف عملاء الولاء حسب إجمالي الطلبات ومتوسط قيمة الطلب.",
        ],

        "loyalty_tier_customer_distribution" => [
            "title" => "توزيع العملاء حسب المستوى",
            "description" => "كيفية توزيع عملاء الولاء على المستويات المختلفة.",
        ],

        "loyalty_tier_performance" => [
            "title" => "أداء المستويات",
            "description" => "إجمالي الإنفاق والنقاط المكتسبة لكل مستوى.",
        ],

        "loyalty_tier_redemption_rate" => [
            "title" => "معدل الاستبدال لكل مستوى",
            "description" => "النقاط المكتسبة مقابل المستبدلة مقسمة حسب المستوى.",
        ],

        "loyalty_rewards_list" => [
            "title" => "قائمة المكافآت",
            "description" => "قائمة جميع مكافآت الولاء مع الحالة والقيمة.",
        ],

        "loyalty_most_redeemed_rewards" => [
            "title" => "أكثر المكافآت استبدالاً",
            "description" => "المكافآت ذات أعلى عدد استبدالات.",
        ],

        "loyalty_least_used_rewards" => [
            "title" => "أقل المكافآت استخداماً",
            "description" => "المكافآت ذات أقل عدد من الاستبدالات.",
        ],

        "loyalty_never_redeemed_rewards" => [
            "title" => "مكافآت لم تُستبدل مطلقاً",
            "description" => "المكافآت التي لم يتم استبدالها أبداً.",
        ],

        "loyalty_rewards_by_type" => [
            "title" => "المكافآت حسب النوع",
            "description" => "المكافآت مجمعة حسب النوع مع إجمالي الاستبدالات.",
        ],

        "loyalty_rewards_by_tier" => [
            "title" => "المكافآت حسب المستوى",
            "description" => "المكافآت المتاحة لكل مستوى ولاء.",
        ],

        "loyalty_rewards_by_program" => [
            "title" => "المكافآت حسب البرنامج",
            "description" => "المكافآت مجمعة حسب برنامج الولاء.",
        ],

        "loyalty_available_gifts" => [
            "title" => "الهدايا المتاحة",
            "description" => "الهدايا المتاحة للاستخدام.",
        ],

        "loyalty_used_gifts" => [
            "title" => "الهدايا المستخدمة",
            "description" => "الهدايا التي تم استبدالها.",
        ],

        "loyalty_expired_gifts" => [
            "title" => "الهدايا المنتهية",
            "description" => "الهدايا التي انتهت صلاحيتها دون استخدام.",
        ],

        "loyalty_gift_usage_rate" => [
            "title" => "معدل استخدام الهدايا",
            "description" => "معدل استخدام الهدايا المصدرة.",
        ],

        "loyalty_unused_gifts_per_customer" => [
            "title" => "الهدايا غير المستخدمة لكل عميل",
            "description" => "الهدايا غير المستخدمة مجمعة حسب العميل.",
        ],

        "loyalty_redemptions_by_status" => [
            "title" => "الاستبدالات حسب الحالة",
            "description" => "الاستبدالات مجمعة حسب الحالة.",
        ],

        "loyalty_redemptions_by_program" => [
            "title" => "الاستبدالات حسب البرنامج",
            "description" => "الاستبدالات مجمعة حسب برنامج الولاء.",
        ],

        "loyalty_average_points_per_redemption" => [
            "title" => "متوسط النقاط لكل استبدال",
            "description" => "متوسط النقاط المصروفة لكل استبدال.",
        ],

        "loyalty_active_promotions" => [
            "title" => "الحملات النشطة",
            "description" => "الحملات النشطة حالياً.",
        ],

        "loyalty_expired_promotions" => [
            "title" => "الحملات المنتهية",
            "description" => "الحملات التي انتهت.",
        ],

        "loyalty_promotion_usage" => [
            "title" => "استخدام الحملات",
            "description" => "عدد الاستخدام لكل حملة.",
        ],

        "loyalty_highest_impact_promotions" => [
            "title" => "أعلى الحملات تأثيراً",
            "description" => "الحملات ذات أعلى نقاط متولدة.",
        ],

        "loyalty_bonus_vs_multiplier_comparison" => [
            "title" => "مقارنة الحوافز مقابل المضاعف",
            "description" => "مقارنة حملات النقاط الإضافية مع حملات المضاعف.",
        ],

        "loyalty_category_boost_promotions" => [
            "title" => "حملات تعزيز الفئات",
            "description" => "الحملات التي تعزز فئات محددة.",
        ],

        "loyalty_new_member_promotions" => [
            "title" => "حملات الأعضاء الجدد",
            "description" => "حملات تخص الأعضاء الجدد في الولاء.",
        ],

        "loyalty_free_items_cost" => [
            "title" => "تكلفة العناصر المجانية",
            "description" => "تكلفة العناصر المجانية التي تم إصدارها كمكافآت.",
        ],

        "loyalty_revenue_from_loyalty_customers" => [
            "title" => "إيراد عملاء الولاء",
            "description" => "الإيراد الناتج من عملاء برنامج الولاء.",
        ],

        "loyalty_revenue_before_after_loyalty" => [
            "title" => "الإيراد قبل وبعد الولاء",
            "description" => "مقارنة الإيراد للطلبات مع الولاء وبدونه.",
        ],

        "loyalty_average_order_value_loyalty_customers" => [
            "title" => "متوسط قيمة الطلب (عملاء الولاء)",
            "description" => "متوسط قيمة الطلب لعملاء الولاء.",
        ],
    ],

    "menu_engineering" => [
        "classifications" => [
            "star" => "منتج نجم",
            "workhorse" => "منتج عالي الطلب",
            "puzzle" => "منتج يحتاج تسويق",
            "dog" => "منتج ضعيف الأداء",
        ],
        "recommendations" => [
            "star" => "حافظ على وضوحه وجودته، ويمكن إضافته إلى العروض المركبة.",
            "workhorse" => "الطلب مرتفع والهامش أقل. راجع تكلفة الوصفة أو حجم الحصة أو السعر.",
            "puzzle" => "الهامش جيد لكن الطلب منخفض. حسّن موضعه أو صورته أو توصية الموظفين له.",
            "dog" => "الطلب والهامش منخفضان. فكر في استبداله أو إعادة تسعيره أو إزالته.",
        ],
    ],

    "slow_moving_products" => [
        "recommendation" => "راجع موضع المنتج أو تكلفة الوصفة أو السعر أو الصورة أو توصية الموظفين، أو أزله إذا بقي الطلب منخفضاً.",
    ],

    "export_failed" => "فشل التصدير. يرجى المحاولة مرة أخرى لاحقاً."
];
