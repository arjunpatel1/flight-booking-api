<?php

namespace Modules\Inventory\Services\InventoryAlert;

use Illuminate\Support\Collection;

interface InventoryAlertServiceInterface
{
    public function notifyLowStock(?int $branchId = null, int $limit = 100): int;

    public function notifyLowStockForIngredients(array|Collection $ingredientIds): int;
}
