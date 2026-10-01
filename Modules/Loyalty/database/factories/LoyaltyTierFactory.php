<?php

namespace Modules\Loyalty\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Loyalty\Models\LoyaltyTier;

class LoyaltyTierFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = LoyaltyTier::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $tiers = [
            [
                'name' => ['en' => 'Silver', 'ar' => 'فضي'],
                'min_spend' => 0,
                'multiplier' => 1.00,
                'benefits' => [
                    'en' => 'Earn 1 point per currency unit spent.',
                    'ar' => 'اكسب نقطة واحدة مقابل كل وحدة عملة يتم إنفاقها.',
                ],
            ],
            [
                'name' => ['en' => 'Gold', 'ar' => 'ذهبي'],
                'min_spend' => 1000,
                'multiplier' => 1.25,
                'benefits' => [
                    'en' => 'Earn 25% faster. Free birthday dessert.',
                    'ar' => 'اكسب نقاطًا أسرع بنسبة 25٪. حلوى مجانية في عيد ميلادك.',
                ],
            ],
            [
                'name' => ['en' => 'Platinum', 'ar' => 'بلاتيني'],
                'min_spend' => 3000,
                'multiplier' => 1.50,
                'benefits' => [
                    'en' => 'Earn 50% faster. VIP seating & priority service.',
                    'ar' => 'اكسب نقاطًا أسرع بنسبة 50٪. مقاعد خاصة وخدمة أولوية.',
                ],
            ],
        ];

        $tier = $this->faker->randomElement($tiers);

        return [
            'name' => $tier['name'],
            'min_spend' => $tier['min_spend'],
            'multiplier' => $tier['multiplier'],
            'benefits' => $tier['benefits'],
            "is_active" => $this->faker->randomElement([true, false]),
        ];
    }
}

