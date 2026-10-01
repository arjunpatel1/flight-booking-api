<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Intelligence\TelemetryRecorder;
use Modules\Support\ApiResponse;

class RecommendationTelemetryController
{
    public function __construct(protected TelemetryRecorder $recorder) {}

    /**
     * Record a recommendation lifecycle event (shown/accepted/ignored/...) by its
     * tracking_id. Best-effort telemetry for the NexDine Intelligence Layer.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tracking_id' => 'required|string|max:64',
            'event' => 'required|string|in:' . implode(',', TelemetryRecorder::EVENTS),
            'type' => 'nullable|string|max:64',
            'confidence' => 'nullable|numeric|min:0|max:1',
            'meta' => 'nullable|array',
        ]);

        $recorded = $this->recorder->record(
            trackingId: $data['tracking_id'],
            event: $data['event'],
            type: $data['type'] ?? null,
            confidence: isset($data['confidence']) ? (float) $data['confidence'] : null,
            meta: $data['meta'] ?? [],
        );

        return ApiResponse::success(['recorded' => $recorded]);
    }
}
