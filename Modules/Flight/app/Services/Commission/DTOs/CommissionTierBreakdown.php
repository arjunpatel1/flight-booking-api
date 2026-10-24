<?php

namespace Modules\Flight\Services\Commission\DTOs;

use Modules\Flight\Enums\CommissionRecipientTier;

class CommissionTierBreakdown
{
    public function __construct(
        public CommissionRecipientTier $recipientTier,
        public ?int $recipientId,
        public int $ruleId,
        public string $ruleName,
        public float $baseAmount,
        public float $ratePercentage,
        public float $fixedAmount,
        public float $commissionAmount,
        public bool $minCapApplied = false,
        public bool $maxCapApplied = false,
        public string $currency = 'INR',
    ) {}

    public function toArray(): array
    {
        return [
            'recipient_tier'    => $this->recipientTier->value,
            'recipient_tier_label' => $this->recipientTier->label(),
            'recipient_id'      => $this->recipientId,
            'rule_id'           => $this->ruleId,
            'rule_name'         => $this->ruleName,
            'base_amount'       => $this->baseAmount,
            'rate_percentage'   => $this->ratePercentage,
            'fixed_amount'      => $this->fixedAmount,
            'commission_amount' => $this->commissionAmount,
            'min_cap_applied'   => $this->minCapApplied,
            'max_cap_applied'   => $this->maxCapApplied,
            'currency'          => $this->currency,
        ];
    }
}
