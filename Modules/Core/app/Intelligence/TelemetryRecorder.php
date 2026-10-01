<?php

namespace Modules\Core\Intelligence;

use Modules\Core\Models\RecommendationTelemetry;

/**
 * The one sink for recommendation telemetry in the NexDine Intelligence Layer.
 *
 * Records lifecycle events for a recommendation by its `tracking_id` so
 * acceptance/ignore/success rates are measurable. Best-effort and append-only —
 * never throws into the caller (telemetry must never break a user action).
 */
final class TelemetryRecorder
{
    public const EVENTS = ['shown', 'accepted', 'ignored', 'successful', 'failed'];

    /**
     * @param array<string,mixed> $meta
     */
    public function record(
        string $trackingId,
        string $event,
        ?string $type = null,
        ?float $confidence = null,
        array $meta = [],
    ): bool {
        if ($trackingId === '' || ! in_array($event, self::EVENTS, true)) {
            return false;
        }

        try {
            $user = auth()->user();

            RecommendationTelemetry::create([
                'tracking_id' => $trackingId,
                'recommendation_type' => $type,
                'event' => $event,
                'branch_id' => $user?->branch_id,
                'user_id' => $user?->id,
                'confidence' => $confidence,
                'meta' => $meta !== [] ? $meta : null,
                'created_at' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
