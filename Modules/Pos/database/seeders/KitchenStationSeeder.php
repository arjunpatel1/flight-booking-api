<?php

namespace Modules\Pos\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Modules\Branch\Models\Branch;
use Modules\Category\Models\Category;
use Modules\Pos\Models\KitchenStation;

class KitchenStationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = Category::query()
            ->select(['id', 'name', 'slug'])
            ->where('is_active', true)
            ->orderBy('order')
            ->get();

        if ($categories->isEmpty()) {
            return;
        }

        foreach (Branch::query()->where('is_active', true)->get() as $branch) {
            $assignedCategoryIds = collect();

            foreach ($this->stationDefinitions() as $definition) {
                $matchedCategories = $this->matchCategories($categories, $definition['keywords'])
                    ->reject(fn(Category $category) => $assignedCategoryIds->contains($category->id))
                    ->values();

                if ($matchedCategories->isEmpty() && !$definition['fallback']) {
                    continue;
                }

                if ($definition['fallback']) {
                    $matchedCategories = $categories
                        ->reject(fn(Category $category) => $assignedCategoryIds->contains($category->id))
                        ->values();
                }

                if ($matchedCategories->isEmpty()) {
                    continue;
                }

                $station = KitchenStation::query()
                    ->where('branch_id', $branch->id)
                    ->where('name->en', $definition['name']['en'])
                    ->first();

                if (!$station) {
                    $station = new KitchenStation([
                        'branch_id' => $branch->id,
                    ]);
                }

                $station->fill([
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'display_order' => $definition['display_order'],
                    'is_active' => true,
                    'prep_time_minutes' => $definition['prep_time_minutes'],
                    'max_concurrent_items' => $definition['max_concurrent_items'],
                    'color' => $definition['color'],
                    'sound_enabled' => true,
                    'auto_bump_minutes' => $definition['auto_bump_minutes'],
                ]);
                $station->save();

                $syncPayload = $matchedCategories
                    ->mapWithKeys(fn(Category $category) => [
                        $category->id => [
                            'priority' => $definition['display_order'],
                            'prep_time_override' => $definition['prep_time_minutes'],
                        ],
                    ])
                    ->all();

                $station->assignedCategories()->syncWithoutDetaching($syncPayload);
                $assignedCategoryIds = $assignedCategoryIds->merge($matchedCategories->pluck('id'))->unique()->values();
            }
        }
    }

    private function matchCategories(Collection $categories, array $keywords): Collection
    {
        return $categories->filter(function (Category $category) use ($keywords) {
            $translations = collect($category->getTranslations('name'))->implode(' ');
            $haystack = strtolower(trim($category->slug . ' ' . $translations));

            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    private function stationDefinitions(): array
    {
        return [
            [
                'name' => ['en' => 'Hot Kitchen', 'ar' => 'المطبخ الساخن'],
                'description' => ['en' => 'Main cooking station for hot food and grill items.', 'ar' => 'محطة الطهي الرئيسية للأطباق الساخنة والمشاوي.'],
                'keywords' => ['hot', 'main', 'meat', 'chicken', 'grill', 'fried', 'pizza', 'burger', 'seafood', 'soup', 'breakfast'],
                'display_order' => 10,
                'prep_time_minutes' => 18,
                'max_concurrent_items' => 20,
                'color' => '#EF4444',
                'auto_bump_minutes' => 0,
                'fallback' => false,
            ],
            [
                'name' => ['en' => 'Cold Kitchen', 'ar' => 'المطبخ البارد'],
                'description' => ['en' => 'Cold appetizers, salads, and fast prep items.', 'ar' => 'المقبلات الباردة والسلطات والأصناف سريعة التحضير.'],
                'keywords' => ['cold', 'salad', 'appetizer', 'yogurt', 'fruit'],
                'display_order' => 20,
                'prep_time_minutes' => 10,
                'max_concurrent_items' => 18,
                'color' => '#22C55E',
                'auto_bump_minutes' => 0,
                'fallback' => false,
            ],
            [
                'name' => ['en' => 'Beverage Station', 'ar' => 'محطة المشروبات'],
                'description' => ['en' => 'Hot and cold beverages station.', 'ar' => 'محطة المشروبات الساخنة والباردة.'],
                'keywords' => ['beverage', 'drink', 'tea', 'coffee', 'juice', 'soda', 'smoothie'],
                'display_order' => 30,
                'prep_time_minutes' => 7,
                'max_concurrent_items' => 24,
                'color' => '#0EA5E9',
                'auto_bump_minutes' => 0,
                'fallback' => false,
            ],
            [
                'name' => ['en' => 'Dessert Station', 'ar' => 'محطة الحلويات'],
                'description' => ['en' => 'Desserts, cakes, and sweets station.', 'ar' => 'محطة الحلويات والكعك والأصناف الحلوة.'],
                'keywords' => ['dessert', 'cake', 'ice', 'cream', 'sweet'],
                'display_order' => 40,
                'prep_time_minutes' => 8,
                'max_concurrent_items' => 16,
                'color' => '#A855F7',
                'auto_bump_minutes' => 0,
                'fallback' => false,
            ],
            [
                'name' => ['en' => 'General Kitchen', 'ar' => 'المطبخ العام'],
                'description' => ['en' => 'Fallback station for categories without a dedicated station.', 'ar' => 'محطة افتراضية للأقسام التي لا تملك محطة مخصصة.'],
                'keywords' => [],
                'display_order' => 90,
                'prep_time_minutes' => 15,
                'max_concurrent_items' => 20,
                'color' => '#64748B',
                'auto_bump_minutes' => 0,
                'fallback' => true,
            ],
        ];
    }
}
