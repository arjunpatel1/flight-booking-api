<?php

namespace Modules\Core\Intelligence;

/**
 * Deterministic score for a single operational signal, produced by the one
 * {@see DecisionEngine}. No LLM, no randomness — every field is rule-derived and
 * explainable. Impact dimensions are qualitative ('high'|'medium'|'low').
 */
final class DecisionScore
{
    public function __construct(
        public readonly string $type,
        public readonly int $priority,
        public readonly string $urgency,        // urgent|high|medium|low
        public readonly float $confidence,      // 0..1
        public readonly string $businessImpact, // high|medium|low
        public readonly string $customerImpact,
        public readonly string $revenueImpact,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'priority' => $this->priority,
            'urgency' => $this->urgency,
            'confidence' => $this->confidence,
            'business_impact' => $this->businessImpact,
            'customer_impact' => $this->customerImpact,
            'revenue_impact' => $this->revenueImpact,
        ];
    }
}
