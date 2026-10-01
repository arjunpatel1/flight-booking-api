<?php

namespace Modules\Saas\Enums;

/**
 * Lifecycle of an onboarding request.
 *
 *   draft ─────────────► awaiting_payment ──(paid)──┐
 *     │                                             │
 *     └──(no payment required)──────────────────────┤
 *                                                   ▼
 *                                          awaiting_approval
 *                                                   │
 *                                    (auto | operator approves)
 *                                                   ▼
 *                                               approved
 *                                                   │
 *                                                   ▼
 *                                             provisioning ──► completed
 *                                                   │
 *                                                   └──► failed ──(retry)──┘
 *
 * `rejected` and `cancelled` are terminal decisions made by a human.
 */
enum OnboardingRequestStatus: string
{
    case Draft = 'draft';
    case AwaitingPayment = 'awaiting_payment';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Provisioning = 'provisioning';
    case Completed = 'completed';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Terminal states never transition again without an explicit reopen. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Cancelled], true);
    }

    /** A failed run is the only state an operator may retry in place. */
    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
