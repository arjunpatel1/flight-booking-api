<?php

namespace Modules\Flight\Services\Servicing;

use Illuminate\Support\Carbon;
use Modules\Flight\Models\FlightBooking;
use Modules\Saas\Models\Tenant;

/**
 * Tenant-configurable cancellation & refund policy, modelled on how Indian
 * airlines and OTAs charge (DGCA passenger charter):
 *  - a per-passenger cancellation charge that grows as departure approaches,
 *  - never more than the base fare (statutory taxes & airport fees are always
 *    refundable, even on non-refundable fares and no-shows),
 *  - a free "look-in" window shortly after booking for trips far enough out,
 *  - an optional agency service fee and a non-refundable convenience fee.
 *
 * Stored in tenants.settings.flight_booking.refund_policy.
 */
class FlightRefundPolicyService
{
    public const DEFAULT_POLICY = [
        'enabled' => true,
        // Charges per passenger, by time left before departure. Evaluated top to
        // bottom; the first slab whose min_hours_before_departure is met applies.
        'slabs' => [
            ['min_hours_before_departure' => 96, 'fee_type' => 'fixed', 'fee_value' => 1500, 'label' => 'More than 4 days before departure'],
            ['min_hours_before_departure' => 24, 'fee_type' => 'fixed', 'fee_value' => 3000, 'label' => '1 to 4 days before departure'],
            ['min_hours_before_departure' => 3, 'fee_type' => 'fixed', 'fee_value' => 3500, 'label' => '3 to 24 hours before departure'],
        ],
        // Inside this many hours (or after departure) the booking is a no-show:
        // only statutory taxes & fees are refunded.
        'no_show_cutoff_hours' => 3,
        // Airlines may not charge more than the base fare as a cancellation fee.
        'cap_fee_at_base_fare' => true,
        // Statutory taxes/airport fees are refunded on no-shows and non-refundable fares.
        'refund_taxes_on_no_show' => true,
        'convenience_fee_refundable' => false,
        // Agency processing charge, per passenger, on every refunded cancellation.
        'service_fee_per_passenger' => 300,
        // Free cancellation shortly after booking (DGCA "look-in" option).
        'look_in' => [
            'enabled' => true,
            'hours_after_booking' => 48,
            'min_days_before_departure' => 7,
        ],
        'refund_processing_days' => 7,
        'policy_notes' => 'Refunds are credited to the original payment method within 7 working days of cancellation. Statutory taxes and airport fees are always refunded. Convenience fees are non-refundable.',
    ];

    public function policyFor(?Tenant $tenant): array
    {
        $stored = (array) data_get($tenant?->settings, 'flight_booking.refund_policy', []);
        $policy = array_replace_recursive(self::DEFAULT_POLICY, $stored);
        // Lists replace, not merge: a saved policy with 2 slabs must not keep
        // the default's 3rd slab.
        if (isset($stored['slabs']) && is_array($stored['slabs'])) {
            $policy['slabs'] = $stored['slabs'];
        }

        return $this->normalize($policy);
    }

    /** Validation rules for the admin form. */
    public static function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'slabs' => ['required', 'array', 'min:1', 'max:10'],
            'slabs.*.min_hours_before_departure' => ['required', 'integer', 'min:0', 'max:8760'],
            'slabs.*.fee_type' => ['required', 'in:fixed,percent'],
            'slabs.*.fee_value' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'slabs.*.label' => ['nullable', 'string', 'max:120'],
            'no_show_cutoff_hours' => ['required', 'integer', 'min:0', 'max:72'],
            'cap_fee_at_base_fare' => ['required', 'boolean'],
            'refund_taxes_on_no_show' => ['required', 'boolean'],
            'convenience_fee_refundable' => ['required', 'boolean'],
            'service_fee_per_passenger' => ['required', 'numeric', 'min:0', 'max:100000'],
            'look_in.enabled' => ['required', 'boolean'],
            'look_in.hours_after_booking' => ['required', 'integer', 'min:0', 'max:720'],
            'look_in.min_days_before_departure' => ['required', 'integer', 'min:0', 'max:365'],
            'refund_processing_days' => ['required', 'integer', 'min:0', 'max:90'],
            'policy_notes' => ['nullable', 'string', 'max:4000'],
        ];
    }

    public function normalize(array $policy): array
    {
        $slabs = array_values(array_map(fn (array $slab) => [
            'min_hours_before_departure' => max(0, (int) ($slab['min_hours_before_departure'] ?? 0)),
            'fee_type' => ($slab['fee_type'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed',
            'fee_value' => max(0, (float) ($slab['fee_value'] ?? 0)),
            'label' => trim((string) ($slab['label'] ?? '')),
        ], (array) ($policy['slabs'] ?? [])));
        // Furthest-out slab first so the first match is the right one.
        usort($slabs, fn ($a, $b) => $b['min_hours_before_departure'] <=> $a['min_hours_before_departure']);

        return [
            'enabled' => (bool) ($policy['enabled'] ?? true),
            'slabs' => $slabs,
            'no_show_cutoff_hours' => max(0, (int) ($policy['no_show_cutoff_hours'] ?? 0)),
            'cap_fee_at_base_fare' => (bool) ($policy['cap_fee_at_base_fare'] ?? true),
            'refund_taxes_on_no_show' => (bool) ($policy['refund_taxes_on_no_show'] ?? true),
            'convenience_fee_refundable' => (bool) ($policy['convenience_fee_refundable'] ?? false),
            'service_fee_per_passenger' => max(0, (float) ($policy['service_fee_per_passenger'] ?? 0)),
            'look_in' => [
                'enabled' => (bool) data_get($policy, 'look_in.enabled', false),
                'hours_after_booking' => max(0, (int) data_get($policy, 'look_in.hours_after_booking', 0)),
                'min_days_before_departure' => max(0, (int) data_get($policy, 'look_in.min_days_before_departure', 0)),
            ],
            'refund_processing_days' => max(0, (int) ($policy['refund_processing_days'] ?? 0)),
            'policy_notes' => trim((string) ($policy['policy_notes'] ?? '')),
        ];
    }

    /**
     * Work out what a cancellation costs right now and what is refunded.
     *
     * @param  bool  $fareRefundable  the fare's own refundability flag
     * @param  float|null  $fareRuleFeePerPassenger  airline fee from the fare rule, overriding slabs
     */
    public function quote(FlightBooking $booking, array $policy, bool $fareRefundable, ?float $fareRuleFeePerPassenger = null, ?Carbon $now = null): array
    {
        $now ??= now();
        $currency = $booking->currency ?: 'INR';
        $total = round((float) $booking->total_amount, 2);
        $pricing = (array) ($booking->pricing_breakdown ?? []);
        $passengers = max(1, (int) $booking->pax_count_adult + (int) $booking->pax_count_child + (int) $booking->pax_count_infant);

        $convenienceFee = min($total, (float) ($pricing['convenience_fee'] ?? 0));
        $taxes = (float) ($pricing['taxes'] ?? 0) + (float) ($pricing['gst'] ?? 0);
        // What the customer actually paid towards the fare itself.
        $baseFare = max(0, $total - $convenienceFee - $taxes);
        $taxes = min($taxes, $total - $convenienceFee);

        $departure = $this->departureAt($booking);
        $hoursToDeparture = $departure ? $now->diffInMinutes($departure, false) / 60 : null;

        $lines = [];
        $rule = '';
        $airlineFee = 0.0;

        if (! $policy['enabled']) {
            $rule = 'Refund policy disabled: full fare refund less non-refundable fees.';
        } elseif ($this->inLookInWindow($booking, $policy, $now, $hoursToDeparture)) {
            $rule = sprintf('Free cancellation: within %d hours of booking.', $policy['look_in']['hours_after_booking']);
        } elseif ($hoursToDeparture !== null && $hoursToDeparture < $policy['no_show_cutoff_hours']) {
            $rule = $hoursToDeparture < 0
                ? 'Flight has departed (no-show): only taxes and fees are refunded.'
                : sprintf('Within %d hours of departure (no-show): only taxes and fees are refunded.', $policy['no_show_cutoff_hours']);
            $airlineFee = $baseFare;
        } elseif (! $fareRefundable) {
            $rule = 'Non-refundable fare: only taxes and fees are refunded.';
            $airlineFee = $baseFare;
        } else {
            $perPassenger = $fareRuleFeePerPassenger;
            if ($perPassenger !== null) {
                $rule = 'Airline cancellation charge from the fare rules.';
            } else {
                $slab = $this->slabFor($policy['slabs'], $hoursToDeparture);
                $perPassenger = $slab === null ? 0.0 : ($slab['fee_type'] === 'percent'
                    ? round(($baseFare / $passengers) * $slab['fee_value'] / 100, 2)
                    : $slab['fee_value']);
                $rule = ($slab['label'] ?? '') ?: 'Standard cancellation charge.';
            }
            $airlineFee = $perPassenger * $passengers;
            if ($policy['cap_fee_at_base_fare']) {
                $airlineFee = min($airlineFee, $baseFare);
            }
        }

        $refundsTaxes = $airlineFee < $baseFare || $policy['refund_taxes_on_no_show'] || ! $policy['enabled'];
        $taxesKept = $refundsTaxes ? 0.0 : $taxes;
        $convenienceKept = $policy['convenience_fee_refundable'] ? 0.0 : $convenienceFee;
        $serviceFee = $policy['enabled'] ? $policy['service_fee_per_passenger'] * $passengers : 0.0;

        $airlineFee = round(min($airlineFee, $baseFare), 2);
        $deductions = $airlineFee + $taxesKept + $convenienceKept;
        $serviceFee = round(min($serviceFee, max(0, $total - $deductions)), 2);
        $refundable = round(max(0, $total - $deductions - $serviceFee), 2);
        $cancellationFee = round($total - $refundable, 2);

        $lines[] = ['label' => 'Amount paid', 'amount' => $total];
        if ($airlineFee > 0) {
            $lines[] = ['label' => 'Airline cancellation charge', 'amount' => -$airlineFee];
        }
        if ($taxesKept > 0) {
            $lines[] = ['label' => 'Taxes & fees (not refunded)', 'amount' => -round($taxesKept, 2)];
        }
        if ($convenienceKept > 0) {
            $lines[] = ['label' => 'Convenience fee (non-refundable)', 'amount' => -round($convenienceKept, 2)];
        }
        if ($serviceFee > 0) {
            $lines[] = ['label' => sprintf('Agency service fee (%d passenger%s)', $passengers, $passengers === 1 ? '' : 's'), 'amount' => -$serviceFee];
        }
        $lines[] = ['label' => 'Refund amount', 'amount' => $refundable];

        return [
            'booking_reference' => $booking->booking_reference,
            'is_refundable' => $refundable > 0,
            'original_amount' => $total,
            'cancellation_fee' => $cancellationFee,
            'refundable_amount' => $refundable,
            'currency' => $currency,
            'passengers' => $passengers,
            'hours_to_departure' => $hoursToDeparture === null ? null : round($hoursToDeparture, 1),
            'applied_rule' => $rule,
            'breakdown' => $lines,
            'refund_processing_days' => $policy['refund_processing_days'],
            'policy_text' => trim($rule . ' ' . $policy['policy_notes']),
        ];
    }

    private function slabFor(array $slabs, ?float $hoursToDeparture): ?array
    {
        foreach ($slabs as $slab) {
            if ($hoursToDeparture === null || $hoursToDeparture >= $slab['min_hours_before_departure']) {
                return $slab;
            }
        }

        // Between the last slab and the no-show cutoff: use the nearest slab.
        return $slabs === [] ? null : $slabs[array_key_last($slabs)];
    }

    private function inLookInWindow(FlightBooking $booking, array $policy, Carbon $now, ?float $hoursToDeparture): bool
    {
        $lookIn = $policy['look_in'];
        if (! $lookIn['enabled'] || ! $booking->created_at || $hoursToDeparture === null) {
            return false;
        }

        return $booking->created_at->copy()->addHours($lookIn['hours_after_booking'])->gte($now)
            && $hoursToDeparture >= $lookIn['min_days_before_departure'] * 24;
    }

    /** Itineraries store departure as {date,time}, departure_date + "HH:MM", or a full datetime. */
    public function departureAt(FlightBooking $booking): ?Carbon
    {
        $itinerary = (array) ($booking->itinerary ?? []);
        $date = data_get($itinerary, 'departure.date') ?: data_get($itinerary, 'departure_date');
        $time = (string) (data_get($itinerary, 'departure.time') ?: data_get($itinerary, 'departure_time') ?: '');

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $time)) {
                return Carbon::parse($time);
            }
            if ($date) {
                $clock = preg_match('/\d{1,2}:\d{2}(:\d{2})?/', $time, $m) ? $m[0] : '00:00';

                return Carbon::parse(substr((string) $date, 0, 10) . ' ' . $clock);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
