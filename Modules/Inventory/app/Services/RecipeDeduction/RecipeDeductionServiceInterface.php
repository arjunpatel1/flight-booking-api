<?php

namespace Modules\Inventory\Services\RecipeDeduction;

use Illuminate\Support\Collection;
use Modules\Order\Models\Order;

interface RecipeDeductionServiceInterface
{
    /**
     * Deduct ingredients when an order is placed.
     *
     * @param Order $order
     * @return Collection<int, array{ingredient_id: int, quantity: float, deducted: bool, message?: string}>
     */
    public function deductForOrder(Order $order): Collection;

    /**
     * Reverse ingredient deductions for an order (e.g., on cancel/refund).
     *
     * @param Order $order
     * @param string|null $reason
     * @return Collection<int, array{ingredient_id: int, quantity: float, restored: bool, message?: string}>
     */
    public function restoreForOrder(Order $order, ?string $reason = null): Collection;

    /**
     * Check if all ingredients are available for an order.
     *
     * @param Order|Collection $orderOrProducts Order model or collection of order products
     * @param int|null $branchId
     * @return array{
     *     available: bool,
     *     shortages: array<int, array{ingredient_id: int, name: string, required: float, available: float}>
     * }
     */
    public function checkAvailability(Order|Collection $orderOrProducts, ?int $branchId = null): array;

    /**
     * Get projected ingredient usage for a collection of products.
     *
     * @param Collection $orderProducts
     * @return Collection<int, array{ingredient_id: int, name: string, total_quantity: float, unit: string}>
     */
    public function projectIngredientUsage(Collection $orderProducts): Collection;

    /**
     * Deduct ingredients for a specific product quantity.
     *
     * @param int $productId
     * @param float $quantity
     * @param int $branchId
     * @param int|null $orderId
     * @return Collection<int, array{ingredient_id: int, quantity: float, deducted: bool, message?: string}>
     */
    public function deductForProduct(int $productId, float $quantity, int $branchId, ?int $orderId = null): Collection;
}
