<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Category\Models\Category;
use Modules\Inventory\Models\Ingredient;
use Modules\Menu\Models\Menu;
use Modules\Option\Models\Option;
use Modules\Product\Enums\IngredientOperation;
use Modules\Product\Models\Ingredientable;
use Modules\Product\Models\Product;
use Modules\Product\Services\ProductOption\ProductOptionServiceInterface;
use Modules\Support\Money;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = require "demo_products.php";

        $categories = [];
        foreach (Menu::all() as $menu) {
            foreach ($data as $categoryName => $products) {
                if (!isset($categories[$categoryName])) {
                    $category = Category::query()
                        ->where('menu_id', $menu->id)
                        ->withoutGlobalActive()
                        ->whereLikeTranslation('name', $categoryName)
                        ->first();
                    if (!is_null($category)) {
                        $categories[$categoryName] = $category;
                    } else {
                        continue;
                    }
                } else {
                    $category = $categories[$categoryName];
                }
                foreach ($products as $data) {
                    /** @var Product $product */
                    $product = Product::query()
                        ->withoutGlobalActive()
                        ->where('menu_id', $menu->id)
                        ->where('name->en', $data['name']['en'])
                        ->first();

                    if ($product) {
                        $product->fill([
                            'name' => $data['name'],
                            'description' => $data['description'],
                            'is_active' => true,
                        ]);
                        $product->save();
                    } else {
                        $product = Product::factory()
                            ->create([
                                'name' => $data['name'],
                                'description' => $data['description'],
                                'menu_id' => $menu->id,
                            ]);
                    }

                    $product->categories()->syncWithoutDetaching([$category->id]);
                    $this->createIngredientables($product, $menu->branch_id);
                    $this->createOptions($product, $menu->branch_id);

                }
            }
        }
    }

    /**
     * Create ingredientables
     *
     * @param Product $product
     * @param int $branchId
     * @return void
     */
    private function createIngredientables(Product $product, int $branchId): void
    {
        $ingredients = Ingredient::query()
            ->where("branch_id", $branchId)
            ->inRandomOrder()
            ->pluck('id');

        if ($ingredients->isEmpty() || $product->ingredients()->exists()) {
            return;
        }

        $count = min(rand(1, 10), $ingredients->count());

        foreach ($ingredients->shuffle()->take($count) as $ingredientId) {
            Ingredientable::factory()
                ->create([
                    "ingredient_id" => $ingredientId,
                    'ingredientable_id' => $product->id,
                    'ingredientable_type' => $product->getMorphClass(),
                    'priority' => 10,
                    'branch_id' => $branchId,
                    'operation' => IngredientOperation::Add,
                ]);

        }
    }

    /**
     * Create options
     *
     * @param Product $product
     * @param int $branchId
     * @return void
     */
    private function createOptions(Product $product, int $branchId): void
    {
        $options = Option::query()
            ->with("values")
            ->where('branch_id', $branchId)
            ->inRandomOrder()
            ->limit(rand(1, 5))
            ->get()
            ->map(function ($option) {
                return [
                    "name" => $option->getTranslations("name"),
                    "type" => $option->type->value,
                    "is_required" => $option->is_required,
                    "values" => $option->values->map(function ($value) {
                        return [
                            "label" => $value->getTranslations("label"),
                            "price" => $value->price instanceof Money ? $value->price->amount() : $value->price,
                            "price_type" => $value->price_type,
                        ];
                    })
                        ->toArray(),
                ];
            })
            ->toArray();

        if ($options === []) {
            return;
        }

        $productOptionService = app(ProductOptionServiceInterface::class);
        $productOptionService->syncOptions($product, $options);
    }
}
