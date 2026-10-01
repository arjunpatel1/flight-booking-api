<?php

namespace Modules\Core\Intelligence;

/**
 * NexDine Intelligence Layer — the one Prediction Engine.
 *
 * Deterministic, explainable forecasts (no LLM). Pure functions over the shared
 * {@see RestaurantContext} and explicit live counts supplied by the caller, so
 * predictions are testable and never reach into the database themselves.
 */
final class PredictionEngine
{
    /**
     * Rush-hour likelihood from time-of-day context alone (shift + peak window +
     * weekend). No external data required.
     */
    public function predictRushHour(RestaurantContext $context): Prediction
    {
        $inPeakWindow = ($context->hour >= 12 && $context->hour <= 14)
            || ($context->hour >= 19 && $context->hour <= 22);

        $base = match ($context->shift) {
            'dinner' => 0.70,
            'lunch' => 0.60,
            'morning' => 0.30,
            default => 0.15,
        };

        $likelihood = min(1.0, round($base + ($inPeakWindow ? 0.20 : 0.0) + ($context->isWeekend ? 0.10 : 0.0), 2));

        return new Prediction(
            type: 'rush_hour',
            likely: $likelihood >= 0.6,
            likelihood: $likelihood,
            confidence: 0.75,
            reason: $inPeakWindow
                ? ucfirst($context->shift) . ' peak window' . ($context->isWeekend ? ' on a weekend' : '') . ' — expect higher order volume.'
                : ucfirst($context->shift) . ' service' . ($context->isWeekend ? ' on a weekend' : '') . ' — moderate volume expected.',
            expectedWindowMinutes: $inPeakWindow ? 0 : $this->minutesToNextPeak($context->hour),
        );
    }

    /**
     * Kitchen-delay likelihood from current in-flight kitchen load vs SLA.
     * Caller supplies the live count of active (in-kitchen) orders.
     */
    public function predictKitchenDelay(int $inFlight, int $slaMinutes = 25): Prediction
    {
        // Each in-flight order adds ~1.5 min of expected pressure against the SLA.
        $expectedMinutes = (int) round($inFlight * 1.5);
        $likelihood = min(1.0, round($expectedMinutes / max(1, $slaMinutes), 2));

        return new Prediction(
            type: 'kitchen_delay',
            likely: $expectedMinutes >= $slaMinutes,
            likelihood: $likelihood,
            confidence: 0.8,
            reason: $inFlight > 0
                ? "{$inFlight} order(s) in the kitchen (~{$expectedMinutes} min) against a {$slaMinutes} min SLA."
                : 'Kitchen is clear — no delay expected.',
            expectedWindowMinutes: $expectedMinutes,
        );
    }

    /**
     * Table-release likelihood after payment — paid tables free up soon.
     */
    public function predictTableRelease(int $minutesSincePaid): Prediction
    {
        $likelihood = min(1.0, round($minutesSincePaid / 10, 2));

        return new Prediction(
            type: 'table_release',
            likely: $minutesSincePaid >= 3,
            likelihood: $likelihood,
            confidence: 0.7,
            reason: $minutesSincePaid >= 3
                ? "Table paid {$minutesSincePaid} min ago — likely to free up shortly."
                : 'Recently paid — guests may still be seated.',
            expectedWindowMinutes: max(0, 10 - $minutesSincePaid),
        );
    }

    private function minutesToNextPeak(int $hour): int
    {
        foreach ([12, 19] as $peakStart) {
            if ($hour < $peakStart) {
                return ($peakStart - $hour) * 60;
            }
        }

        // After the dinner peak — next peak is tomorrow's lunch.
        return ((24 - $hour) + 12) * 60;
    }
}
