<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Enums\UnitType;
use Modules\Inventory\Models\Ingredient;
use Modules\Inventory\Models\Unit;

class IngredientSeeder extends Seeder
{
    /**
     * Define ingredients
     *
     * @var array|array[]
     */
    private array $ingredients = [
        ['en' => 'Tomatoes', 'ar' => 'طماطم', 'unit_type' => UnitType::Mass],
        ['en' => 'Cherry Tomatoes', 'ar' => 'طماطم كرزية', 'unit_type' => UnitType::Mass],
        ['en' => 'Onions', 'ar' => 'بصل', 'unit_type' => UnitType::Mass],
        ['en' => 'Red Onions', 'ar' => 'بصل أحمر', 'unit_type' => UnitType::Mass],
        ['en' => 'Garlic', 'ar' => 'ثوم', 'unit_type' => UnitType::Mass],
        ['en' => 'Cucumber', 'ar' => 'خيار', 'unit_type' => UnitType::Mass],
        ['en' => 'Lettuce', 'ar' => 'خس', 'unit_type' => UnitType::Mass],
        ['en' => 'Iceberg Lettuce', 'ar' => 'خس آيسبيرغ', 'unit_type' => UnitType::Mass],
        ['en' => 'Carrots', 'ar' => 'جزر', 'unit_type' => UnitType::Mass],
        ['en' => 'Potatoes', 'ar' => 'بطاطس', 'unit_type' => UnitType::Mass],
        ['en' => 'Sweet Potatoes', 'ar' => 'بطاطا حلوة', 'unit_type' => UnitType::Mass],
        ['en' => 'Bell Peppers', 'ar' => 'فلفل رومي', 'unit_type' => UnitType::Mass],
        ['en' => 'Green Peppers', 'ar' => 'فلفل أخضر', 'unit_type' => UnitType::Mass],
        ['en' => 'Jalapenos', 'ar' => 'هالبينو', 'unit_type' => UnitType::Mass],
        ['en' => 'Mushrooms', 'ar' => 'فطر', 'unit_type' => UnitType::Mass],
        ['en' => 'Zucchini', 'ar' => 'كوسا', 'unit_type' => UnitType::Mass],
        ['en' => 'Eggplant', 'ar' => 'باذنجان', 'unit_type' => UnitType::Mass],
        ['en' => 'Spinach', 'ar' => 'سبانخ', 'unit_type' => UnitType::Mass],
        ['en' => 'Broccoli', 'ar' => 'بروكلي', 'unit_type' => UnitType::Mass],
        ['en' => 'Cauliflower', 'ar' => 'قرنبيط', 'unit_type' => UnitType::Mass],
        ['en' => 'Green Beans', 'ar' => 'فاصوليا خضراء', 'unit_type' => UnitType::Mass],

        ['en' => 'Lemons', 'ar' => 'ليمون', 'unit_type' => UnitType::Mass],
        ['en' => 'Limes', 'ar' => 'ليمون أخضر', 'unit_type' => UnitType::Mass],
        ['en' => 'Oranges', 'ar' => 'برتقال', 'unit_type' => UnitType::Mass],
        ['en' => 'Apples', 'ar' => 'تفاح', 'unit_type' => UnitType::Mass],
        ['en' => 'Bananas', 'ar' => 'موز', 'unit_type' => UnitType::Mass],
        ['en' => 'Pineapple', 'ar' => 'أناناس', 'unit_type' => UnitType::Mass],
        ['en' => 'Strawberries', 'ar' => 'فراولة', 'unit_type' => UnitType::Mass],
        ['en' => 'Blueberries', 'ar' => 'توت أزرق', 'unit_type' => UnitType::Mass],
        ['en' => 'Grapes', 'ar' => 'عنب', 'unit_type' => UnitType::Mass],
        ['en' => 'Mango', 'ar' => 'مانجو', 'unit_type' => UnitType::Mass],
        ['en' => 'Avocado', 'ar' => 'أفوكادو', 'unit_type' => UnitType::Mass],

        ['en' => 'Beef', 'ar' => 'لحم بقري', 'unit_type' => UnitType::Mass],
        ['en' => 'Ground Beef', 'ar' => 'لحم مفروم', 'unit_type' => UnitType::Mass],
        ['en' => 'Steak', 'ar' => 'شريحة لحم', 'unit_type' => UnitType::Mass],
        ['en' => 'Lamb', 'ar' => 'لحم غنم', 'unit_type' => UnitType::Mass],
        ['en' => 'Chicken Breast', 'ar' => 'صدر دجاج', 'unit_type' => UnitType::Mass],
        ['en' => 'Chicken Thighs', 'ar' => 'ورك دجاج', 'unit_type' => UnitType::Mass],
        ['en' => 'Whole Chicken', 'ar' => 'دجاج كامل', 'unit_type' => UnitType::Count],
        ['en' => 'Turkey', 'ar' => 'ديك رومي', 'unit_type' => UnitType::Mass],

        ['en' => 'Fish Fillet', 'ar' => 'فيليه سمك', 'unit_type' => UnitType::Mass],
        ['en' => 'Salmon', 'ar' => 'سلمون', 'unit_type' => UnitType::Mass],
        ['en' => 'Tuna', 'ar' => 'تونة', 'unit_type' => UnitType::Mass],
        ['en' => 'Shrimp', 'ar' => 'جمبري', 'unit_type' => UnitType::Mass],
        ['en' => 'Calamari', 'ar' => 'كاليماري', 'unit_type' => UnitType::Mass],
        ['en' => 'Crab', 'ar' => 'سلطعون', 'unit_type' => UnitType::Mass],

        ['en' => 'Milk', 'ar' => 'حليب', 'unit_type' => UnitType::Volume],
        ['en' => 'Yogurt', 'ar' => 'لبن زبادي', 'unit_type' => UnitType::Volume],
        ['en' => 'Butter', 'ar' => 'زبدة', 'unit_type' => UnitType::Mass],
        ['en' => 'Cream', 'ar' => 'كريمة', 'unit_type' => UnitType::Volume],
        ['en' => 'Cheddar Cheese', 'ar' => 'جبنة شيدر', 'unit_type' => UnitType::Mass],
        ['en' => 'Mozzarella Cheese', 'ar' => 'جبنة موزاريلا', 'unit_type' => UnitType::Mass],
        ['en' => 'Parmesan Cheese', 'ar' => 'جبنة بارميزان', 'unit_type' => UnitType::Mass],
        ['en' => 'Feta Cheese', 'ar' => 'جبنة فيتا', 'unit_type' => UnitType::Mass],

        ['en' => 'Flour', 'ar' => 'طحين', 'unit_type' => UnitType::Mass],
        ['en' => 'Rice', 'ar' => 'أرز', 'unit_type' => UnitType::Mass],
        ['en' => 'Pasta', 'ar' => 'معكرونة', 'unit_type' => UnitType::Mass],
        ['en' => 'Bread', 'ar' => 'خبز', 'unit_type' => UnitType::Count],
        ['en' => 'Breadcrumbs', 'ar' => 'فتات الخبز', 'unit_type' => UnitType::Mass],
        ['en' => 'Couscous', 'ar' => 'كسكسي', 'unit_type' => UnitType::Mass],
        ['en' => 'Lentils', 'ar' => 'عدس', 'unit_type' => UnitType::Mass],
        ['en' => 'Chickpeas', 'ar' => 'حمص', 'unit_type' => UnitType::Mass],
        ['en' => 'Beans', 'ar' => 'فاصوليا', 'unit_type' => UnitType::Mass],

        ['en' => 'Salt', 'ar' => 'ملح', 'unit_type' => UnitType::Mass],
        ['en' => 'Black Pepper', 'ar' => 'فلفل أسود', 'unit_type' => UnitType::Mass],
        ['en' => 'Paprika', 'ar' => 'بابريكا', 'unit_type' => UnitType::Mass],
        ['en' => 'Cumin', 'ar' => 'كمون', 'unit_type' => UnitType::Mass],
        ['en' => 'Oregano', 'ar' => 'أوريجانو', 'unit_type' => UnitType::Mass],
        ['en' => 'Basil', 'ar' => 'ريحان', 'unit_type' => UnitType::Mass],
        ['en' => 'Rosemary', 'ar' => 'إكليل الجبل', 'unit_type' => UnitType::Mass],
        ['en' => 'Mint', 'ar' => 'نعناع', 'unit_type' => UnitType::Mass],
        ['en' => 'Chili Powder', 'ar' => 'بودرة الفلفل الحار', 'unit_type' => UnitType::Mass],

        ['en' => 'Tomato Paste', 'ar' => 'معجون طماطم', 'unit_type' => UnitType::Volume],
        ['en' => 'Ketchup', 'ar' => 'كاتشب', 'unit_type' => UnitType::Volume],
        ['en' => 'Mayonnaise', 'ar' => 'مايونيز', 'unit_type' => UnitType::Volume],
        ['en' => 'Mustard', 'ar' => 'خردل', 'unit_type' => UnitType::Volume],
        ['en' => 'Soy Sauce', 'ar' => 'صلصة الصويا', 'unit_type' => UnitType::Volume],
        ['en' => 'Vinegar', 'ar' => 'خل', 'unit_type' => UnitType::Volume],
        ['en' => 'Hot Sauce', 'ar' => 'صلصة حارة', 'unit_type' => UnitType::Volume],

        ['en' => 'Water', 'ar' => 'مياه', 'unit_type' => UnitType::Volume],
        ['en' => 'Sparkling Water', 'ar' => 'مياه غازية', 'unit_type' => UnitType::Volume],
        ['en' => 'Coca-Cola', 'ar' => 'كوكاكولا', 'unit_type' => UnitType::Count],
        ['en' => 'Orange Juice', 'ar' => 'عصير برتقال', 'unit_type' => UnitType::Volume],
        ['en' => 'Apple Juice', 'ar' => 'عصير تفاح', 'unit_type' => UnitType::Volume],
        ['en' => 'Tea', 'ar' => 'شاي', 'unit_type' => UnitType::Mass],
        ['en' => 'Coffee', 'ar' => 'قهوة', 'unit_type' => UnitType::Mass],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Branch::all()->each(function ($branch) {
            foreach ($this->ingredients as $item) {
                $unitId = Unit::query()
                    ->where('type', $item['unit_type']->value)
                    ->inRandomOrder()
                    ->value('id');

                Ingredient::factory()
                    ->create([
                        'name' => ['en' => $item['en'], 'ar' => $item['ar']],
                        'unit_id' => $unitId,
                        'branch_id' => $branch->id,
                    ]);
            }
        });
    }
}
