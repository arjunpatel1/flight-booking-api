<?php

namespace Modules\Flight\Services\Commission;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Flight\Enums\CommissionRecipientTier;
use Modules\Flight\Models\FlightCommissionLog;
use Modules\Flight\Models\FlightCommissionRule;
use Modules\Flight\Services\Commission\DTOs\CommissionCalculationRequest;
use Modules\Flight\Services\Commission\DTOs\CommissionCalculationResult;
use Modules\Flight\Services\Commission\DTOs\CommissionTierBreakdown;

/**
 * Phase 7: Deterministic Flight Commission Engine
 *
 * Implements configurable commission calculations across the distribution hierarchy:
 *   Super Admin -> Tenant -> Distributor -> Agent
 *
 * Guaranteed Properties:
 *  - 100% deterministic (given same flight parameters and rules, output is identical)
 *  - Strictly decoupled from customer-facing recommended fare scoring (commercial margin isolation)
 *  - Precedence by priority, specificity, and override flags
 *  - Comprehensive audit trail in flight_commission_logs
 */
class FlightCommissionEngine
{
    /**
     * Calculate commissions across hierarchy tiers deterministically.
     */
    public function calculate(CommissionCalculationRequest $request, bool $recordAudit = true): CommissionCalculationResult
    {
        $breakdowns = [];
        $totalCommission = 0.0;
        $logIds = [];

        // Evaluate tiers in hierarchical sequence
        $tiers = [
            CommissionRecipientTier::TENANT,
            CommissionRecipientTier::DISTRIBUTOR,
            CommissionRecipientTier::AGENT,
        ];

        foreach ($tiers as $tier) {
            $breakdown = $this->calculateForTier($tier, $request);
            if ($breakdown !== null) {
                $breakdowns[] = $breakdown;
                $totalCommission += $breakdown->commissionAmount;

                if ($recordAudit) {
                    $log = $this->recordAuditLog($breakdown, $request);
                    if ($log) {
                        $logIds[] = $log->id;
                    }
                }
            }
        }

        return new CommissionCalculationResult(
            totalCommission:    round($totalCommission, 2),
            currency:           $request->currency,
            tierBreakdowns:     $breakdowns,
            calculationContext: $request->toArray(),
            logIds:             $logIds,
        );
    }

    /**
     * Calculate commission for a specific tier.
     */
    public function calculateForTier(CommissionRecipientTier $tier, CommissionCalculationRequest $request): ?CommissionTierBreakdown
    {
        $recipientId = match ($tier) {
            CommissionRecipientTier::TENANT      => $request->tenantId,
            CommissionRecipientTier::DISTRIBUTOR => $request->distributorId,
            CommissionRecipientTier::AGENT       => $request->agentId,
            default                              => null,
        };

        // Query candidate active rules for this tier
        $query = FlightCommissionRule::query()
            ->active()
            ->effectiveAt(Carbon::now())
            ->forTier($tier, $recipientId);

        // Enforce tenant isolation for non-super-admin rules
        if ($request->tenantId !== null) {
            $query->where(function ($q) use ($request) {
                $q->whereNull('tenant_id')
                  ->orWhere('tenant_id', $request->tenantId);
            });
        }

        $candidates = $query->orderBy('priority', 'desc')->orderBy('id', 'desc')->get();

        // Find the highest precedence matching rule
        /** @var FlightCommissionRule|null $matchedRule */
        $matchedRule = null;

        foreach ($candidates as $rule) {
            if ($rule->matches($request)) {
                $matchedRule = $rule;
                break; // Highest priority rule wins
            }
        }

        if (! $matchedRule) {
            return null;
        }

        // Deterministic amount computation
        $computed = $matchedRule->compute($request->baseFare, $request->totalFare, $request->passengersCount);

        return new CommissionTierBreakdown(
            recipientTier:    $tier,
            recipientId:      $recipientId,
            ruleId:           $matchedRule->id,
            ruleName:         $matchedRule->name,
            baseAmount:       $request->baseFare,
            ratePercentage:   $computed['rate_percentage'],
            fixedAmount:      $computed['fixed_amount'],
            commissionAmount: $computed['amount'],
            minCapApplied:    $computed['min_applied'],
            maxCapApplied:    $computed['max_applied'],
            currency:         $matchedRule->currency ?? $request->currency,
        );
    }

    /**
     * Find all candidate rules matching a request context.
     */
    public function findMatchingRules(CommissionCalculationRequest $request, ?CommissionRecipientTier $tier = null): Collection
    {
        $query = FlightCommissionRule::query()
            ->active()
            ->effectiveAt(Carbon::now());

        if ($tier !== null) {
            $query->forTier($tier);
        }

        if ($request->tenantId !== null) {
            $query->where(function ($q) use ($request) {
                $q->whereNull('tenant_id')
                  ->orWhere('tenant_id', $request->tenantId);
            });
        }

        $candidates = $query->orderBy('priority', 'desc')->get();

        return $candidates->filter(fn(FlightCommissionRule $r) => $r->matches($request))->values();
    }

    /**
     * Write immutable audit log record.
     */
    protected function recordAuditLog(CommissionTierBreakdown $breakdown, CommissionCalculationRequest $request): ?FlightCommissionLog
    {
        try {
            return FlightCommissionLog::create([
                'tenant_id'                 => $request->tenantId,
                'flight_commission_rule_id' => $breakdown->ruleId,
                'booking_reference'         => $request->bookingReference,
                'recipient_tier'            => $breakdown->recipientTier->value,
                'recipient_id'              => $breakdown->recipientId,
                'base_amount'               => $breakdown->baseAmount,
                'commission_amount'         => $breakdown->commissionAmount,
                'rate_percentage'           => $breakdown->ratePercentage,
                'fixed_amount'              => $breakdown->fixedAmount,
                'currency'                  => $breakdown->currency,
                'breakdown'                 => $breakdown->toArray(),
                'calculation_context'       => $request->toArray(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to write flight commission audit log: ' . $e->getMessage());
            return null;
        }
    }
}
