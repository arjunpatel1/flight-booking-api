<?php

namespace Modules\Core\Intelligence;

/**
 * Deterministic restaurant operational-health score for the NexDine Intelligence
 * Layer — the single source of the health formula (no module recomputes it).
 *
 * Derived purely from the active operational cards: critical/warning counts and
 * a small penalty for high-priority unresolved signals. No LLM.
 */
final class HealthScore
{
    public function __construct(
        public readonly int $score,        // 0..100
        public readonly string $status,    // healthy|attention_required|critical
        public readonly string $label,
        public readonly int $criticalCount,
        public readonly int $warningCount,
    ) {
    }

    /**
     * @param array<int,array<string,mixed>> $visibleCards Already-visible cards.
     */
    public static function fromCards(array $visibleCards): self
    {
        $critical = 0;
        $warning = 0;
        $penaltyRaw = 0;

        foreach ($visibleCards as $card) {
            $severity = $card['severity'] ?? 'info';
            if ($severity === 'critical') {
                $critical++;
            } elseif ($severity === 'warning') {
                $warning++;
            }
            $penaltyRaw += max(0, ((int) ($card['priority_score'] ?? 0)) - 70);
        }

        $penalty = min(20, (int) floor($penaltyRaw / 12));
        $score = max(0, 100 - ($critical * 14) - ($warning * 7) - $penalty);

        $status = match (true) {
            $score < 55 || $critical >= 3 => 'critical',
            $score < 80 || $critical > 0 || $warning >= 2 => 'attention_required',
            default => 'healthy',
        };

        return new self(
            score: $score,
            status: $status,
            label: match ($status) {
                'critical' => 'Critical',
                'attention_required' => 'Needs attention',
                default => 'Healthy',
            },
            criticalCount: $critical,
            warningCount: $warning,
        );
    }

    /**
     * @return array{score:int,status:string,label:string,critical_count:int,warning_count:int}
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'status' => $this->status,
            'label' => $this->label,
            'critical_count' => $this->criticalCount,
            'warning_count' => $this->warningCount,
        ];
    }
}
