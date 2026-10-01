<?php

namespace Modules\Pos\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Pos\Models\PosRegister;

class PosRegisterFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = PosRegister::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $names = [
            ['en' => 'Front Counter 1', 'ar' => 'كاونتر أمامي 1'],
            ['en' => 'Front Counter 2', 'ar' => 'كاونتر أمامي 2'],
            ['en' => 'Drive-Thru Window', 'ar' => 'نافذة الطلبات الخارجية'],
            ['en' => 'Outdoor Counter', 'ar' => 'كاونتر خارجي'],
            ['en' => 'Cashier Desk 1', 'ar' => 'منضدة الكاشير 1'],
            ['en' => 'Cashier Desk 2', 'ar' => 'منضدة الكاشير 2'],
            ['en' => 'Coffee Bar', 'ar' => 'ركن القهوة'],
            ['en' => 'Dessert Station', 'ar' => 'ركن الحلويات'],
            ['en' => 'Bakery Counter', 'ar' => 'كاونتر المخبوزات'],
        ];

        $selectedName = $this->faker->randomElement($names);

        return [
            'branch_id' => null,
            'name' => $selectedName,
            'code' => strtoupper($this->faker->unique()->bothify('POS-########')),
            'is_active' => $this->faker->boolean(90),
            'note' => [
                'en' => $this->faker->optional()->sentence(),
                'ar' => $this->faker->optional()->sentence(),
            ],
        ];
    }
}

