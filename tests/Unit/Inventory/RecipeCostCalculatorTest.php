<?php

namespace Tests\Unit\Inventory;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Inventory\Models\Ingredient;
use Modules\Inventory\Services\RecipeCost\RecipeCostCalculator;
use Modules\Option\Models\Option;
use Modules\Option\Models\OptionValue;
use Modules\Product\Enums\IngredientOperation;
use Modules\Product\Models\Ingredientable;
use Modules\Product\Models\Product;
use Tests\TestCase;

class RecipeCostCalculatorTest extends TestCase
{
    public function test_product_cost_includes_modifier_cost_range(): void
    {
        $baseIngredient = $this->ingredient(1, 10);
        $modifierIngredient = $this->ingredient(2, 5);

        $baseLine = $this->ingredientable($baseIngredient, 2);
        $modifierLine = $this->ingredientable($modifierIngredient, 3, IngredientOperation::Add);

        $optionValue = new OptionValue(['id' => 1, 'label' => 'Extra cheese']);
        $optionValue->setRelation('ingredients', new EloquentCollection([$modifierLine]));

        $option = new Option(['id' => 1, 'name' => 'Extras', 'is_required' => false]);
        $option->setRelation('values', new EloquentCollection([$optionValue]));

        $product = new Product();
        $product->currency = 'INR';
        $product->setRelation('ingredients', new EloquentCollection([$baseLine]));
        $product->setRelation('options', new EloquentCollection([$option]));

        $cost = (new RecipeCostCalculator())->calculateProductCost($product);

        $this->assertSame(20.0, $cost['total_cost']);
        $this->assertTrue($cost['has_modifiers']);
        $this->assertSame(0.0, $cost['modifier_cost_min']);
        $this->assertSame(15.0, $cost['modifier_cost_max']);
        $this->assertSame(35.0, $cost['max_possible_cost']);
    }

    private function ingredient(int $id, float $cost): Ingredient
    {
        $ingredient = new Ingredient(['cost_per_unit' => $cost]);
        $ingredient->id = $id;
        $ingredient->currency = 'INR';
        $ingredient->setRelation('unit', null);

        return $ingredient;
    }

    private function ingredientable(
        Ingredient $ingredient,
        float $quantity,
        IngredientOperation $operation = IngredientOperation::Add
    ): Ingredientable {
        $ingredientable = new Ingredientable([
            'ingredient_id' => $ingredient->id,
            'quantity' => $quantity,
            'loss_pct' => 0,
            'operation' => $operation,
        ]);
        $ingredientable->setRelation('ingredient', $ingredient);

        return $ingredientable;
    }
}
