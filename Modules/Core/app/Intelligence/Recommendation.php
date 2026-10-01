<?php

namespace Modules\Core\Intelligence;

use Illuminate\Support\Str;

/**
 * The ONE canonical recommendation contract for the NexDine Intelligence Layer.
 *
 * Every intelligent surface (Copilot, Assistant cards, search suggestions,
 * recommendation chips, …) must emit this exact shape — no module invents its
 * own recommendation payload. Deterministic + explainable: every recommendation
 * carries its reason, confidence and a human explanation.
 */
final class Recommendation
{
    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $businessImpact
     * @param array<string,mixed>|null $actionPolicy
     */
    public function __construct(
        public readonly string $type,
        public readonly int $priority,
        public readonly float $confidence,
        public readonly string $reason,
        public readonly array $context,
        public readonly ?int $expectedTimeSeconds,
        public readonly array $businessImpact,
        public readonly ?string $action,
        public readonly ?array $actionPolicy,
        public readonly string $explanation,
        public readonly string $trackingId,
    ) {
    }

    /**
     * Build from a {@see DecisionScore} plus presentation/explanation fields,
     * so the Decision Engine remains the single scoring source.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed>|null $actionPolicy
     */
    public static function fromScore(
        DecisionScore $score,
        string $reason,
        string $explanation,
        ?string $action = null,
        ?int $expectedTimeSeconds = null,
        array $context = [],
        ?array $actionPolicy = null,
        ?string $trackingId = null,
    ): self {
        return new self(
            type: $score->type,
            priority: $score->priority,
            confidence: $score->confidence,
            reason: $reason,
            context: $context,
            expectedTimeSeconds: $expectedTimeSeconds,
            businessImpact: [
                'business' => $score->businessImpact,
                'customer' => $score->customerImpact,
                'revenue' => $score->revenueImpact,
            ],
            action: $action,
            actionPolicy: $actionPolicy,
            explanation: $explanation,
            trackingId: $trackingId ?? (string) Str::uuid(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'priority' => $this->priority,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'context' => $this->context,
            'expected_time' => $this->expectedTimeSeconds,
            'business_impact' => $this->businessImpact,
            'action' => $this->action,
            'action_policy' => $this->actionPolicy,
            'explanation' => $this->explanation,
            'tracking_id' => $this->trackingId,
        ];
    }
}
