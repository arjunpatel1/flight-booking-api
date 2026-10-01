<?php

namespace Modules\Core\Intelligence;

/**
 * Restaurant Memory Engine (RME) composite scoring — the ONE deterministic
 * product-recommendation weighting for the NexDine Intelligence Layer.
 *
 * Weight hierarchy (strongest cap first): restaurant history > shift affinity >
 * table affinity ≈ waiter affinity > pairing lift. Fully deterministic and
 * explainable (no LLM). Any module ranking products must compose scores here —
 * never reinvent the weights.
 */
final class MemoryScore
{
    public const RESTAURANT_WEIGHT = 4;
    public const RESTAURANT_CAP = 500;
    public const SHIFT_WEIGHT = 8;
    public const SHIFT_CAP = 300;
    public const WAITER_WEIGHT = 12;
    public const WAITER_CAP = 260;
    public const TABLE_WEIGHT = 14;
    public const TABLE_CAP = 260;
    public const PAIRING_WEIGHT = 12;
    public const PAIRING_CAP = 180;

    /**
     * Compose the weighted recommendation score and its breakdown.
     *
     * @return array{recommendation_score:int,popularity_score:int,shift_score:int,waiter_score:int,table_score:int,pairing_score:int}
     */
    public static function compose(
        int $restaurantQuantity,
        int $shiftQuantity,
        int $waiterQuantity,
        int $tableQuantity,
        int|float $pairingScore,
    ): array {
        $restaurant = (int) min(self::RESTAURANT_CAP, $restaurantQuantity * self::RESTAURANT_WEIGHT);
        $shift = (int) min(self::SHIFT_CAP, $shiftQuantity * self::SHIFT_WEIGHT);
        $waiter = (int) min(self::WAITER_CAP, $waiterQuantity * self::WAITER_WEIGHT);
        $table = (int) min(self::TABLE_CAP, $tableQuantity * self::TABLE_WEIGHT);
        $pairing = (int) min(self::PAIRING_CAP, $pairingScore * self::PAIRING_WEIGHT);

        return [
            'recommendation_score' => $restaurant + $shift + $waiter + $table + $pairing,
            'popularity_score' => $restaurant,
            'shift_score' => $shift,
            'waiter_score' => $waiter,
            'table_score' => $table,
            'pairing_score' => $pairing,
        ];
    }
}
