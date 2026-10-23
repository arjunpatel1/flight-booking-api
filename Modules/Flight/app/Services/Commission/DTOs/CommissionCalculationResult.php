<?php

namespace Modules\Flight\Services\Commission\DTOs;

class CommissionCalculationResult
{
    /**
     * @param array<CommissionTierBreakdown> $tierBreakdowns
     * @param array<int> $logIds
     */
    public function __construct(
        public float $totalCommission,
        public string $currency,
        public array $tierBreakdowns = [],
        public array $calculationContext = [],
        public array $logIds = [],
    ) {}

    public function toArray(): array
    {
        return [
            'total_commission'    => $this->totalCommission,
            'currency'            => $this->currency,
            'tier_breakdowns'     => array_map(fn($t) => $t->toArray(), $this->tierBreakdowns),
            'calculation_context' => $this->calculationContext,
            'log_ids'             => $this->logIds,
        ];
    }
}
