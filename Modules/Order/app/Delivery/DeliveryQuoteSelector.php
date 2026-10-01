<?php

namespace Modules\Order\Delivery;

final class DeliveryQuoteSelector
{
    /**
     * @param list<DeliveryQuote> $quotes
     * @param array<string, mixed> $settings
     * @return array{ranked: list<DeliveryQuote>, audit: list<array<string, mixed>>}
     */
    public function rank(array $quotes, array $settings, bool $cod): array
    {
        $allowed = array_map('strtolower', (array) ($settings['delivery_provider_codes'] ?? []));
        $maxCost = $settings['maximum_provider_delivery_cost'] ?? null;
        $maxEta = $settings['maximum_delivery_eta_minutes'] ?? null;
        $now = now();
        $audit = [];
        $eligible = [];

        foreach ($quotes as $quote) {
            $reason = match (true) {
                ! $quote->serviceable => 'NOT_SERVICEABLE',
                $quote->expiresAt !== null && $quote->expiresAt->lte($now) => 'QUOTE_EXPIRED',
                $quote->cost < 0 || ($quote->etaMinutes !== null && $quote->etaMinutes < 1) => 'INVALID_QUOTE',
                $allowed !== [] && ! in_array(strtolower($quote->partnerCode), $allowed, true) => 'PARTNER_DISABLED',
                $cod && ! $quote->allowsCod => 'COD_NOT_ALLOWED',
                ! $cod && ! $quote->allowsPrepaid => 'PREPAID_NOT_ALLOWED',
                $maxCost !== null && $quote->cost > (float) $maxCost => 'COST_LIMIT',
                $maxEta !== null && $quote->etaMinutes === null => 'ETA_UNAVAILABLE',
                $maxEta !== null && $quote->etaMinutes > (int) $maxEta => 'ETA_LIMIT',
                default => null,
            };
            $audit[] = [
                'partner_code' => $quote->partnerCode,
                'partner_name' => $quote->partnerName,
                'quote_reference' => $quote->reference,
                'cost' => $quote->cost,
                'eta_minutes' => $quote->etaMinutes,
                'serviceable' => $quote->serviceable,
                'expires_at' => $quote->expiresAt?->toIso8601String(),
                'rejection_reason' => $reason,
                'selected' => false,
            ];
            if ($reason === null) {
                $eligible[] = $quote;
            }
        }

        $strategy = (string) ($settings['delivery_selection_strategy'] ?? 'cheapest');
        usort($eligible, static fn (DeliveryQuote $a, DeliveryQuote $b) => match ($strategy) {
            'fastest' => [$a->etaMinutes ?? PHP_INT_MAX, $a->cost, $a->partnerCode] <=> [$b->etaMinutes ?? PHP_INT_MAX, $b->cost, $b->partnerCode],
            'balanced' => [$a->cost + ($a->etaMinutes ?? PHP_INT_MAX), $a->cost, $a->partnerCode] <=> [$b->cost + ($b->etaMinutes ?? PHP_INT_MAX), $b->cost, $b->partnerCode],
            'priority' => [array_search(strtolower($a->partnerCode), $allowed, true), $a->cost] <=> [array_search(strtolower($b->partnerCode), $allowed, true), $b->cost],
            default => [$a->cost, $a->etaMinutes, $a->partnerCode] <=> [$b->cost, $b->etaMinutes, $b->partnerCode],
        });

        return ['ranked' => $eligible, 'audit' => $audit];
    }
}
