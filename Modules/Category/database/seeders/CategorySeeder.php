<?php

namespace Modules\Category\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Category\Models\Category;
use Modules\Menu\Models\Menu;

class CategorySeeder extends Seeder
{
    /**
     * List of categories
     *
     * @var array|array[]
     */
    private array $categories = [
        [
            'name' => ['en' => 'Appetizers', 'ar' => 'مقبلات'],
            'children' => [
                [
                    'name' => ['en' => 'Cold Appetizers', 'ar' => 'مقبلات باردة'],
                    'children' => [
                        ['name' => ['en' => 'Yogurt Dishes', 'ar' => 'أطباق الزبادي']],
                        ['name' => ['en' => 'Cold Salads', 'ar' => 'سلطات باردة']],
                    ],
                ],
                [
                    'name' => ['en' => 'Hot Appetizers', 'ar' => 'مقبلات ساخنة'],
                    'children' => [
                        ['name' => ['en' => 'Cheese Rolls', 'ar' => 'لفائف الجبن']],
                        ['name' => ['en' => 'Fried Vegetables', 'ar' => 'خضار مقلية']],
                    ],
                ],
            ],
        ],
        [
            'name' => ['en' => 'Main Courses', 'ar' => 'الأطباق الرئيسية'],
            'children' => [
                [
                    'name' => ['en' => 'Meat Dishes', 'ar' => 'أطباق اللحوم'],
                    'children' => [
                        [
                            'name' => ['en' => 'Grilled Meat', 'ar' => 'لحوم مشوية'],
                            'children' => [
                                ['name' => ['en' => 'Spicy Meat', 'ar' => 'لحوم حارة']],
                                ['name' => ['en' => 'Mild Meat', 'ar' => 'لحوم خفيفة']],
                            ]
                        ],
                        ['name' => ['en' => 'Stewed Meat', 'ar' => 'لحوم مطهية']],
                    ],
                ],
                [
                    'name' => ['en' => 'Chicken Dishes', 'ar' => 'أطباق الدجاج'],
                    'children' => [
                        ['name' => ['en' => 'Fried Chicken', 'ar' => 'دجاج مقلي']],
                        ['name' => ['en' => 'Grilled Chicken', 'ar' => 'دجاج مشوي']],
                        ['name' => ['en' => 'Stuffed Chicken', 'ar' => 'دجاج محشي']],
                    ],
                ],
                [
                    'name' => ['en' => 'Vegetarian Dishes', 'ar' => 'أطباق نباتية'],
                    'children' => [
                        ['name' => ['en' => 'Vegetable Stew', 'ar' => 'مرق الخضار']],
                        ['name' => ['en' => 'Grilled Vegetables', 'ar' => 'خضار مشوية']],
                    ],
                ],
            ],
        ],
        [
            'name' => ['en' => 'Seafood', 'ar' => 'مأكولات بحرية'],
            'children' => [
                ['name' => ['en' => 'Grilled Fish', 'ar' => 'سمك مشوي']],
                ['name' => ['en' => 'Shrimp Dishes', 'ar' => 'أطباق الروبيان']],
                ['name' => ['en' => 'Seafood Pasta', 'ar' => 'باستا بالمأكولات البحرية']],
            ],
        ],
        [
            'name' => ['en' => 'Soups', 'ar' => 'الشوربات'],
            'children' => [
                ['name' => ['en' => 'Cream Soups', 'ar' => 'شوربات كريمية']],
                ['name' => ['en' => 'Broth Soups', 'ar' => 'شوربات مرق']],
                ['name' => ['en' => 'Spicy Soups', 'ar' => 'شوربات حارة']],
            ],
        ],
        [
            'name' => ['en' => 'Salads', 'ar' => 'سلطات'],
            'children' => [
                ['name' => ['en' => 'Green Salads', 'ar' => 'سلطات خضراء']],
                ['name' => ['en' => 'Pasta Salads', 'ar' => 'سلطات الباستا']],
                ['name' => ['en' => 'Fruit Salads', 'ar' => 'سلطات فواكه']],
            ],
        ],
        [
            'name' => ['en' => 'Burgers', 'ar' => 'برغر'],
            'children' => [
                ['name' => ['en' => 'Beef Burgers', 'ar' => 'برغر لحم']],
                ['name' => ['en' => 'Chicken Burgers', 'ar' => 'برغر دجاج']],
                ['name' => ['en' => 'Veggie Burgers', 'ar' => 'برغر نباتي']],
            ],
        ],
        [
            'name' => ['en' => 'Pizza', 'ar' => 'بيتزا'],
            'children' => [
                ['name' => ['en' => 'Margherita', 'ar' => 'مارغريتا']],
                ['name' => ['en' => 'Pepperoni', 'ar' => 'بيبروني']],
                ['name' => ['en' => 'Vegetarian Pizza', 'ar' => 'بيتزا نباتية']],
            ],
        ],
        [
            'name' => ['en' => 'Beverages', 'ar' => 'المشروبات'],
            'children' => [
                [
                    'name' => ['en' => 'Hot Drinks', 'ar' => 'مشروبات ساخنة'],
                    'children' => [
                        ['name' => ['en' => 'Tea', 'ar' => 'شاي']],
                        ['name' => ['en' => 'Coffee', 'ar' => 'قهوة']],
                        ['name' => ['en' => 'Hot Chocolate', 'ar' => 'شوكولاتة ساخنة']],
                    ],
                ],
                [
                    'name' => ['en' => 'Cold Drinks', 'ar' => 'مشروبات باردة'],
                    'children' => [
                        ['name' => ['en' => 'Sodas', 'ar' => 'مشروبات غازية']],
                        ['name' => ['en' => 'Juices', 'ar' => 'عصائر']],
                        ['name' => ['en' => 'Smoothies', 'ar' => 'سموذي']],
                    ],
                ],
            ],
        ],
        [
            'name' => ['en' => 'Desserts', 'ar' => 'حلويات'],
            'children' => [
                [
                    'name' => ['en' => 'Cakes', 'ar' => 'كعك'],
                    'children' => [
                        ['name' => ['en' => 'Chocolate Cake', 'ar' => 'كيك الشوكولاتة']],
                        ['name' => ['en' => 'Vanilla Cake', 'ar' => 'كيك الفانيلا']],
                    ],
                ],
                [
                    'name' => ['en' => 'Ice Cream', 'ar' => 'بوظة'],
                    'children' => [
                        ['name' => ['en' => 'Strawberry', 'ar' => 'فراولة']],
                        ['name' => ['en' => 'Mango', 'ar' => 'مانجا']],
                    ],
                ],
            ],
        ],
        [
            'name' => ['en' => 'Breakfast', 'ar' => 'الإفطار'],
            'children' => [
                ['name' => ['en' => 'Omelettes', 'ar' => 'أومليت']],
                ['name' => ['en' => 'Foul & Hummus', 'ar' => 'فول وحمص']],
                ['name' => ['en' => 'Manakish', 'ar' => 'مناقيش']],
            ],
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (Menu::all() as $menu) {
            $this->createCategories($this->categories, $menu->id);
        }
    }

    /**
     * Create categories
     *
     * @param array $nodes
     * @param int $menuId
     * @param int|null $parentId
     * @return void
     */
    private function createCategories(array $nodes, int $menuId, ?int $parentId = null): void
    {
        foreach ($nodes as $node) {
            $slug = str($node['name']['en'])->slug();

            $category = Category::query()
                ->where('menu_id', $menuId)
                ->where('name->en', $node['name']['en'])
                ->first();
            
            if (!$category) {
                $category = new Category([
                    'menu_id' => $menuId,
                    'parent_id' => $parentId,
                ]);
            }

            $category->name = $node['name'];
            $category->slug = $slug;
            $category->is_active = true;
            $category->save();

            if (!empty($node['children'])) {
                $this->createCategories($node['children'], $menuId, $category->id);
            }
        }
    }
}
