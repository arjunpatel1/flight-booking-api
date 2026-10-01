<?php

namespace Modules\Core\Intelligence;

/**
 * A deterministic prediction produced by the one {@see PredictionEngine}.
 * Explainable (carries its reason) and bounded — no LLM, no randomness.
 */
final class Prediction
{
    public function __construct(
        public readonly string $type,                 // rush_hour|kitchen_delay|table_release|...
        public readonly bool $likely,                 // crossed the decision threshold
        public readonly float $likelihood,            // 0..1
        public readonly float $confidence,            // 0..1
        public readonly string $reason,
        public readonly ?int $expectedWindowMinutes,  // when it is expected to happen/clear
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'likely' => $this->likely,
            'likelihood' => $this->likelihood,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'expected_window_minutes' => $this->expectedWindowMinutes,
        ];
    }
}
