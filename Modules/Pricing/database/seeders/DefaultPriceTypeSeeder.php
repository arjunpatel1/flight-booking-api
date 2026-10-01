<?php

namespace Modules\Pricing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Pricing\Enums\PriceTypeRuleType;
use Modules\Pricing\Models\PriceType;

class DefaultPriceTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $priceTypes = [
            [
                'name' => ['en' => 'AC', 'ar' => 'مكيف'],
                'code' => 'AC',
                'rule_type' => PriceTypeRuleType::Flat->value,
                'rule_value' => 20,
                'description' => [
                    'en' => 'Flat add-on charge for air-conditioned seating.',
                    'ar' => 'رسوم إضافية ثابتة للمقاعد المكيفة.',
                ],
            ],
            [
                'name' => ['en' => 'VIP', 'ar' => 'كبار الشخصيات'],
                'code' => 'VIP',
                'rule_type' => PriceTypeRuleType::Percent->value,
                'rule_value' => 10,
                'description' => [
                    'en' => 'Percentage uplift for VIP seating or premium service.',
                    'ar' => 'زيادة بالنسبة المئوية لمقاعد كبار الشخصيات أو الخدمة المميزة.',
                ],
            ],
            [
                'name' => ['en' => 'VVIP', 'ar' => 'كبار الشخصيات المميزين'],
                'code' => 'VVIP',
                'rule_type' => PriceTypeRuleType::Fixed->value,
                'rule_value' => 500,
                'description' => [
                    'en' => 'Fixed premium price for VVIP seating.',
                    'ar' => 'سعر مميز ثابت لمقاعد كبار الشخصيات المميزين.',
                ],
            ],
            [
                'name' => ['en' => 'Terrace', 'ar' => 'الشرفة'],
                'code' => 'TERRACE',
                'rule_type' => PriceTypeRuleType::Flat->value,
                'rule_value' => 50,
                'description' => [
                    'en' => 'Flat add-on charge for terrace seating.',
                    'ar' => 'رسوم إضافية ثابتة لمقاعد الشرفة.',
                ],
            ],
            [
                'name' => ['en' => 'Family Lounge', 'ar' => 'صالة العائلة'],
                'code' => 'FAMILY_LOUNGE',
                'rule_type' => PriceTypeRuleType::Percent->value,
                'rule_value' => 5,
                'description' => [
                    'en' => 'Small percentage uplift for family lounge seating.',
                    'ar' => 'زيادة بسيطة بالنسبة المئوية لمقاعد صالة العائلة.',
                ],
            ],
            [
                'name' => ['en' => 'Self Service', 'ar' => 'خدمة ذاتية'],
                'code' => 'SELF_SERVICE',
                'rule_type' => PriceTypeRuleType::Fixed->value,
                'rule_value' => 0,
                'description' => [
                    'en' => 'Product-level self-service pricing used by POS self-service orders.',
                    'ar' => 'تسعير المنتجات للخدمة الذاتية المستخدم في طلبات نقاط البيع.',
                ],
            ],
        ];

        foreach ($priceTypes as $priceType) {
            PriceType::query()
                ->withoutGlobalActive()
                ->updateOrCreate(
                    ['code' => $priceType['code']],
                    [...$priceType, 'is_active' => true]
                );
        }
    }
}
