<?php

namespace Modules\Loyalty\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Modules\Loyalty\Enums\LoyaltyPromotionType;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyPromotion;
use Modules\Loyalty\Models\LoyaltyTier;

class LoyaltyDemoSeeder extends Seeder
{
    public function run(): void
    {
        $program = LoyaltyProgram::query()
            ->create([
                'name' => ['en' => 'Restaurant Rewards', 'ar' => 'برنامج مكافآت المطعم'],
                'earning_rate' => 1,
                'redemption_rate' => 0.01,
                'min_redeem_points' => 100,
                'points_expire_after' => 365,
                'is_active' => true,
            ]);

        $tiers = [
            [
                'name' => ['en' => 'Bronze', 'ar' => 'برونزي'],
                'min_spend' => 0,
                'multiplier' => 1.0,
                'benefits' => [
                    'en' => 'Earn 1 point per currency unit spent.',
                    'ar' => 'اكسب نقطة واحدة لكل وحدة نقدية تنفقها.',
                ],
                'order' => 1,
            ],
            [
                'name' => ['en' => 'Silver', 'ar' => 'فضي'],
                'min_spend' => 500,
                'multiplier' => 1.2,
                'benefits' => [
                    'en' => 'Earn 20% more points on every order.',
                    'ar' => 'اكسب 20٪ نقاط إضافية على كل طلب.',
                ],
                'order' => 2,
            ],
            [
                'name' => ['en' => 'Gold', 'ar' => 'ذهبي'],
                'min_spend' => 1500,
                'multiplier' => 1.5,
                'benefits' => [
                    'en' => 'Earn 50% more points and enjoy VIP privileges.',
                    'ar' => 'اكسب 50٪ نقاط إضافية وتمتع بامتيازات كبار الشخصيات.',
                ],
                'order' => 3,
            ],
        ];

        foreach ($tiers as $t) {
            LoyaltyTier::query()
                ->create([
                    'loyalty_program_id' => $program->id,
                    'name' => $t['name'],
                    'min_spend' => $t['min_spend'],
                    'multiplier' => $t['multiplier'],
                    'benefits' => $t['benefits'],
                    'order' => $t['order'],
                    'is_active' => true,
                ]);
        }

        $now = Carbon::now();
        $weekAfter = $now->copy()->addWeek();

        $categories = [
            'cold-appetizers', 'yogurt-dishes', 'hot-appetizers',
            'cheese-rolls', 'fried-vegetables', 'appetizers'
        ];

        LoyaltyPromotion::query()
            ->create([
                'loyalty_program_id' => $program->id,
                'name' => [
                    'en' => 'Weekend Double Points',
                    'ar' => 'نقاط مضاعفة في عطلة نهاية الأسبوع',
                ],
                'description' => [
                    'en' => 'Earn double points every Saturday and Sunday.',
                    'ar' => 'اكسب نقاطًا مضاعفة كل يوم سبت وأحد.',
                ],
                'type' => LoyaltyPromotionType::Multiplier,
                'multiplier' => 2.0,
                'bonus_points' => null,
                'usage_limit' => null,
                'per_customer_limit' => null,
                'starts_at' => $now->copy()->subDays(2),
                'ends_at' => $weekAfter,
                'conditions' => [
                    'available_days' => ['sunday', 'saturday'],
                ],
                'is_active' => true,
            ]);

        LoyaltyPromotion::query()
            ->create([
                'loyalty_program_id' => $program->id,
                'name' => [
                    'en' => 'Big Spender Bonus',
                    'ar' => 'مكافأة المنفق الكبير',
                ],
                'description' => [
                    'en' => 'Earn 100 extra points when your order exceeds 50.',
                    'ar' => 'اكسب 100 نقطة إضافية عند تجاوز الطلب 50.',
                ],
                'type' => LoyaltyPromotionType::BonusPoints,
                'bonus_points' => 100,
                'multiplier' => null,
                'usage_limit' => null,
                'per_customer_limit' => null,
                'starts_at' => $now->copy()->subDay(),
                'ends_at' => $weekAfter,
                'conditions' => [
                    'min_spend' => 50,
                ],
                'is_active' => true,
            ]);

        LoyaltyPromotion::query()
            ->create([
                'loyalty_program_id' => $program->id,
                'name' => [
                    'en' => 'Appetizer Boost',
                    'ar' => 'تعزيز المقبلات',
                ],
                'description' => [
                    'en' => 'Earn 50% more points on all appetizer items.',
                    'ar' => 'اكسب 50٪ نقاط إضافية على جميع أطباق المقبلات.',
                ],
                'type' => LoyaltyPromotionType::CategoryBoost,
                'multiplier' => 1.5,
                'bonus_points' => null,
                'usage_limit' => null,
                'per_customer_limit' => null,
                'starts_at' => $now->copy()->subDay(),
                'ends_at' => $weekAfter,
                'conditions' => [
                    'categories' => $categories,
                ],
                'is_active' => true,
            ]);

        LoyaltyPromotion::query()
            ->create([
                'loyalty_program_id' => $program->id,
                'name' => [
                    'en' => 'Welcome Bonus',
                    'ar' => 'مكافأة الترحيب',
                ],
                'description' => [
                    'en' => 'Get 500 bonus points when you join within your first 7 days.',
                    'ar' => 'احصل على 500 نقطة إضافية عند انضمامك خلال أول 7 أيام.',
                ],
                'type' => LoyaltyPromotionType::NewMember,
                'bonus_points' => 500,
                'multiplier' => null,
                'usage_limit' => null,
                'per_customer_limit' => 1,
                'starts_at' => $now->copy()->subDay(),
                'ends_at' => $weekAfter,
                'conditions' => [
                    'valid_days' => 7,
                ],
                'is_active' => true,
            ]);

        $this->command->info('✅ Loyalty demo data created successfully with multilingual fields and enum types!');
    }
}
