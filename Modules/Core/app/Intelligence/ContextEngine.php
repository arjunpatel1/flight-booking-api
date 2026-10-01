<?php

namespace Modules\Core\Intelligence;

/**
 * NexDine Intelligence Layer — the one Context Engine.
 *
 * Assembles the single {@see RestaurantContext} that every recommendation and
 * prediction consumes. Deterministic, no LLM. The shift mapping lives here as
 * the single source of truth (no module derives shifts on its own).
 */
final class ContextEngine
{
    public function current(?int $branchId = null): RestaurantContext
    {
        $now = now();
        $hour = (int) $now->format('G');

        return new RestaurantContext(
            branchId: $branchId,
            time: $now->toISOString(),
            hour: $hour,
            dayOfWeek: (int) $now->isoWeekday(),
            dayName: $now->format('l'),
            isWeekend: $now->isWeekend(),
            shift: self::shiftForHour($hour),
        );
    }

    /**
     * Map an hour-of-day (0..23) to the canonical service shift.
     */
    public static function shiftForHour(int $hour): string
    {
        return match (true) {
            $hour >= 5 && $hour < 11 => 'morning',
            $hour >= 11 && $hour < 16 => 'lunch',
            $hour >= 16 && $hour < 23 => 'dinner',
            default => 'night',
        };
    }
}
