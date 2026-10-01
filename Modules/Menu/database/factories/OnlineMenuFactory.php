<?php

namespace Modules\Menu\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Branch\Models\Branch;
use Modules\Menu\Models\OnlineMenu;
use Str;

class OnlineMenuFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = OnlineMenu::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $name = [
            'en' => $this->faker->words(2, true), // e.g., "Main Menu"
            'ar' => 'القائمة الرئيسية',           // You can customize this
        ];

        $slug = Str::slug($name['en']) . '-' . Str::random(5);

        return [
            'branch_id' => Branch::main()->first()->id ?: Branch::inRandomOrder()->first()?->id,
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ];
    }
}

