<?php

namespace Modules\Product\Services\ProductIngredient;

use Throwable;

interface ProductIngredientServiceInterface
{
    /**
     * Sync ingredients for a Product.
     *
     * @param int $productId
     * @param int $branchId
     * @param array $items
     * @throws Throwable
     */
    public function syncForProduct(int $productId, int $branchId, array $items): void;

    /**
     * Sync ingredients for an Option Value.
     *
     * @param int $optionValueId
     * @param int $branchId
     * @param array $items
     * @return void
     * @throws Throwable
     */
    public function syncForOptionValue(int $optionValueId, int $branchId, array $items): void;
}
