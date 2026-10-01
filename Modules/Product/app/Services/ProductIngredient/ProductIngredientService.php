<?php

namespace Modules\Product\Services\ProductIngredient;

use DB;
use Modules\Option\Models\OptionValue;
use Modules\Product\Enums\IngredientOperation;
use Modules\Product\Models\Ingredientable;
use Modules\Product\Models\Product;
use Modules\Support\Eloquent\Model;
use Throwable;

/**
 * Sync (create/update/delete) ingredient lines for a product configuration.
 *
 * Targets supported via morph:
 *  - Product::class            (priority default 10)
 *  - OptionValue::class        (priority default 30)
 */
class ProductIngredientService implements ProductIngredientServiceInterface
{
    /** @inheritDoc */
    public function syncForProduct(int $productId, int $branchId, array $items): void
    {
        $this->sync(Product::class, $productId, $branchId, $items, defaultPriority: 10);
    }

    /**
     * Core sync method (create/update/delete to match incoming list).
     *
     * @param class-string<Model> $ownerType
     * @param int $ownerId
     * @param int $branchId
     * @param array $items
     * @param int $defaultPriority
     * @throws Throwable
     */
    protected function sync(string $ownerType, int $ownerId, int $branchId, array $items, int $defaultPriority): void
    {
        DB::transaction(function () use ($ownerType, $ownerId, $branchId, $items, $defaultPriority) {
            $rows = collect($items)
                ->values()
                ->filter(fn($row) => $row['ingredient_id'])
                ->map(function (array $row, int $index) use ($branchId, $defaultPriority) {
                    return [
                        'id' => $row['id'] ?? null,
                        "branch_id" => $branchId,
                        'ingredient_id' => (int)$row['ingredient_id'],
                        'quantity' => (float)$row['quantity'],
                        'operation' => isset($row['operation'])
                            ? (IngredientOperation::tryFrom($row['operation']) ?: IngredientOperation::Add)
                            : IngredientOperation::Add,
                        'loss_pct' => isset($row['loss_pct']) ? (float)$row['loss_pct'] : 0.0,
                        'order' => $index,
                        'note' => $row['note'] ?? null,
                        'priority' => (int)($row['priority'] ?? $defaultPriority),
                    ];
                });

            $existing = Ingredientable::query()
                ->where('ingredientable_type', $ownerType)
                ->where('ingredientable_id', $ownerId)
                ->get()
                ->keyBy('id');

            $keepIds = [];

            foreach ($rows as $payload) {
                $id = $payload['id'] ?? null;

                if ($id && $existing->has($id)) {
                    $existingRow = $existing[$id];

                    $existingRow->fill([
                        'branch_id' => $payload['branch_id'],
                        'ingredient_id' => $payload['ingredient_id'],
                        'quantity' => $payload['quantity'],
                        'operation' => $payload['operation'],
                        'loss_pct' => $payload['loss_pct'],
                        'order' => $payload['order'],
                        'note' => $payload['note'],
                        'priority' => $payload['priority'],
                    ])->save();

                    $keepIds[] = (int)$id;
                } else {
                    $created = Ingredientable::query()
                        ->create([
                            'ingredientable_type' => $ownerType,
                            'ingredientable_id' => $ownerId,
                            'branch_id' => $payload['branch_id'],
                            'ingredient_id' => $payload['ingredient_id'],
                            'quantity' => $payload['quantity'],
                            'operation' => $payload['operation'],
                            'loss_pct' => $payload['loss_pct'],
                            'order' => $payload['order'],
                            'note' => $payload['note'],
                            'priority' => $payload['priority'],
                        ]);

                    $keepIds[] = (int)$created->id;
                }
            }

            if (!empty($keepIds)) {
                Ingredientable::query()
                    ->where('ingredientable_type', $ownerType)
                    ->where('ingredientable_id', $ownerId)
                    ->whereNotIn('id', $keepIds)
                    ->delete();
            } else {
                Ingredientable::query()
                    ->where('ingredientable_type', $ownerType)
                    ->where('ingredientable_id', $ownerId)
                    ->delete();
            }
        });
    }

    /** @inheritDoc */
    public function syncForOptionValue(int $optionValueId, int $branchId, array $items): void
    {
        $this->sync(OptionValue::class, $optionValueId, $branchId, $items, defaultPriority: 30);
    }
}
