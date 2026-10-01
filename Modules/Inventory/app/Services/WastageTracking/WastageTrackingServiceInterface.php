<?php

namespace Modules\Inventory\Services\WastageTracking;

use Illuminate\Support\Collection;
use Modules\Inventory\Enums\StockMovementType;

interface WastageTrackingServiceInterface
{
    /**
     * Record wastage for an ingredient.
     *
     * @param int $ingredientId
     * @param float $quantity
     * @param string $reason
     * @param int $branchId
     * @param int|null $reportedBy
     * @param string|null $note
     * @return array{success: bool, movement_id?: int, message?: string}
     */
    public function recordWastage(
        int $ingredientId,
        float $quantity,
        string $reason,
        int $branchId,
        ?int $reportedBy = null,
        ?string $note = null
    ): array;

    /**
     * Get wastage report for a date range.
     *
     * @param string $from
     * @param string $to
     * @param int|null $branchId
     * @param int|null $ingredientId
     * @return Collection<int, array{
     *     ingredient_id: int,
     *     ingredient_name: string,
     *     total_wasted: float,
     *     unit: string,
     *     cost: float,
     *     reason_breakdown: array<string, float>
     * }>
     */
    public function getWastageReport(
        string $from,
        string $to,
        ?int $branchId = null,
        ?int $ingredientId = null
    ): Collection;

    /**
     * Get top wasted ingredients.
     *
     * @param string $from
     * @param string $to
     * @param int $limit
     * @param int|null $branchId
     * @return Collection<int, array{ingredient_id: int, name: string, total_wasted: float, cost: float}>
     */
    public function getTopWastedIngredients(
        string $from,
        string $to,
        int $limit = 10,
        ?int $branchId = null
    ): Collection;

    /**
     * Get wastage summary by reason.
     *
     * @param string $from
     * @param string $to
     * @param int|null $branchId
     * @return Collection<int, array{reason: string, total_quantity: float, cost: float, percentage: float}>
     */
    public function getWastageByReason(
        string $from,
        string $to,
        ?int $branchId = null
    ): Collection;

    /**
     * Get predefined wastage reasons.
     *
     * @return array<string, string>
     */
    public function getWastageReasons(): array;

    /**
     * Get wastage trends over time.
     *
     * @param string $from
     * @param string $to
     * @param string $groupBy 'day'|'week'|'month'
     * @param int|null $branchId
     * @return Collection<int, array{period: string, total_wasted: float, cost: float}>
     */
    public function getWastageTrends(
        string $from,
        string $to,
        string $groupBy = 'day',
        ?int $branchId = null
    ): Collection;
}
