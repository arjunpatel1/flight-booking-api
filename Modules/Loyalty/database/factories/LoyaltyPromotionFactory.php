<?php

namespace Modules\Loyalty\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Category\Models\Category;
use Modules\Loyalty\Enums\LoyaltyPromotionType;
use Modules\Loyalty\Models\LoyaltyPromotion;
use Modules\Support\Enums\Day;

class LoyaltyPromotionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = LoyaltyPromotion::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $faker = $this->faker;

        // Randomly choose a promotion type
        $type = $faker->randomElement(LoyaltyPromotionType::values());

        // Dynamic name & description
        $name = match ($type) {
            LoyaltyPromotionType::Multiplier->value => ['en' => 'Double Points Weekend', 'ar' => 'نقاط مضاعفة في عطلة نهاية الأسبوع'],
            LoyaltyPromotionType::BonusPoints->value => ['en' => 'Spend 50 INR, Get +100 Points', 'ar' => 'احصل على 100 نقطة عند إنفاق 50 د.أ'],
            LoyaltyPromotionType::CategoryBoost->value => ['en' => '3x Points on Pizza', 'ar' => '3x نقاط على البيتزا'],
            LoyaltyPromotionType::NewMember->value => ['en' => 'Welcome Bonus', 'ar' => 'مكافأة الترحيب'],
            default => ['en' => 'Special Promotion', 'ar' => 'عرض خاص'],
        };

        $description = [
            'en' => $faker->sentence(10),
            'ar' => $faker->sentence(10),
        ];

        $conditions = [
            'min_spend' => $faker->randomElement([null, 20, 50, 100]),
            'available_days' => $faker->randomElements(Day::values(), $faker->numberBetween(1, 3)),
        ];

        $multiplier = null;
        $bonusPoints = null;

        switch ($type) {
            case LoyaltyPromotionType::Multiplier->value:
                $multiplier = $faker->randomElement([1.5, 2.0, 3.0]);
                break;

            case LoyaltyPromotionType::BonusPoints->value:
                $bonusPoints = $faker->numberBetween(50, 300);
                break;

            case LoyaltyPromotionType::CategoryBoost->value:
                $multiplier = $faker->randomElement([2.0, 3.0]);
                $conditions['categories'] = [Category::query()->inRandomOrder()->first()->slug];
                break;

            case LoyaltyPromotionType::NewMember->value:
                $bonusPoints = 500;
                break;
        }

        return [
            'name' => $name,
            'description' => $description,
            'type' => $type,
            'multiplier' => $multiplier,
            'bonus_points' => $bonusPoints,
            'conditions' => $conditions,
            'usage_limit' => $faker->randomElement([null, 100, 500]),
            'per_customer_limit' => $faker->randomElement([null, 1, 3, 5]),
            'starts_at' => now()->subDays($faker->numberBetween(0, 10)),
            'ends_at' => now()->addDays($faker->numberBetween(5, 30)),
            'is_active' => $faker->randomElement([true, false]),
        ];
    }
}

