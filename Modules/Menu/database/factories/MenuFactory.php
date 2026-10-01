<?php

namespace Modules\Menu\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Menu\Models\Menu;

class MenuFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Menu::class;

    /**
     * Menus factory
     *
     * @var array
     */
    protected array $menus = [
        [
            'name' => ['en' => 'Breakfast Menu', 'ar' => 'قائمة الإفطار'],
            'description' => ['en' => 'Start your day with a delicious breakfast.', 'ar' => 'ابدأ يومك بإفطار شهي.'],
        ],
        [
            'name' => ['en' => 'Lunch Menu', 'ar' => 'قائمة الغداء'],
            'description' => ['en' => 'Hearty meals perfect for midday.', 'ar' => 'وجبات مشبعة مثالية لمنتصف اليوم.'],
        ],
        [
            'name' => ['en' => 'Dinner Menu', 'ar' => 'قائمة العشاء'],
            'description' => ['en' => 'Relax with our evening specialties.', 'ar' => 'استمتع بتشكيلة العشاء المميزة.'],
        ],
        [
            'name' => ['en' => 'Chef’s Specials', 'ar' => 'الأطباق المميزة'],
            'description' => ['en' => 'Unique dishes crafted by our chef.', 'ar' => 'أطباق فريدة من إعداد الشيف.'],
        ],
        [
            'name' => ['en' => 'Seasonal Menu', 'ar' => 'قائمة موسمية'],
            'description' => ['en' => 'Fresh ingredients for every season.', 'ar' => 'مكونات طازجة لكل موسم.'],
        ],
        [
            'name' => ['en' => 'Kids Menu', 'ar' => 'قائمة الأطفال'],
            'description' => ['en' => 'Fun and tasty meals for little ones.', 'ar' => 'وجبات ممتعة ولذيذة للصغار.'],
        ],
        [
            'name' => ['en' => 'Desserts', 'ar' => 'الحلويات'],
            'description' => ['en' => 'Sweet treats to finish your meal.', 'ar' => 'حلويات شهية لإنهاء وجبتك.'],
        ],
        [
            'name' => ['en' => 'Seafood Selections', 'ar' => 'مأكولات بحرية'],
            'description' => ['en' => 'Freshly prepared seafood dishes.', 'ar' => 'أطباق مأكولات بحرية طازجة.'],
        ],
    ];

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $menu = $this->faker->randomElement($this->menus);

        return [
            'name' => $menu['name'],
            'description' => $menu['description'],
            'is_active' => false,
        ];
    }
}

