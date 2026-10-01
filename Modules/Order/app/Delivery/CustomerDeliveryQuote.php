<?php

namespace Modules\Order\Delivery;

use Modules\Branch\Models\Branch;

/** Authoritative checkout price. Distance uses the same Haversine value as assignment. */
final class CustomerDeliveryQuote
{
    public function settings(): array
    {
        $defaults = ['maximum_delivery_radius_km' => null, 'delivery_pricing_method' => 'slabs',
            'delivery_charge_slabs' => [], 'base_delivery_charge' => 0, 'base_distance_km' => 0,
            'per_additional_km_charge' => 0, 'delivery_customer_fee_gst_enabled' => false,
            'delivery_customer_fee_gst_rate' => 18,
            'delivery_free_rules' => [], 'free_delivery_above_order_amount' => null];
        foreach ($defaults as $key => $default) {
            $defaults[$key] = setting($key, $default);
        }

        return $defaults;
    }

    public function calculate(Branch $branch, array $address, float $subtotal, array $settings): array
    {
        $pickup = DeliveryLocation::fromAddress(['latitude' => $branch->latitude, 'longitude' => $branch->longitude]);
        $dropoff = DeliveryLocation::fromAddress($address);
        if (! $pickup) {
            return $this->failed('RESTAURANT_LOCATION_MISSING', 'Delivery is unavailable because this outlet has no confirmed map location.');
        }
        if (! $dropoff) {
            return $this->failed('CUSTOMER_LOCATION_MISSING', 'Choose a saved address with a map pin, use your location, or select a delivery point manually.');
        }

        $distance = $pickup->distanceTo($dropoff);
        $radii = array_filter([
            $branch->delivery_radius_km,
            $settings['maximum_delivery_radius_km'] ?? null,
        ], static fn ($value) => $value !== null && is_numeric($value) && (float) $value > 0);
        $radius = $radii === [] ? null : min(array_map('floatval', $radii));
        if ($radius !== null && $distance > $radius + 0.000001) {
            return $this->failed('OUTSIDE_DELIVERY_RADIUS', sprintf('This address is outside this outlet\'s %.1f km delivery area.', $radius), $distance);
        }

        $method = (string) ($settings['delivery_pricing_method'] ?? 'slabs');
        $rule = null;
        $base = null;
        if ($method === 'slabs') {
            $slabs = array_values((array) ($settings['delivery_charge_slabs'] ?? []));
            // Never invent a zero delivery fee when slab pricing is enabled
            // without a configured slab. A genuine free-delivery promotion is
            // evaluated below and must still start from a valid base price.
            if ($slabs === []) {
                return $this->failed(
                    'DELIVERY_PRICE_UNAVAILABLE',
                    'Delivery pricing has not been configured for this outlet. Please contact the restaurant.',
                    $distance,
                    true,
                );
            }
            foreach ($slabs as $slab) {
                if (! is_array($slab)) {
                    return $this->failed('DELIVERY_PRICE_UNAVAILABLE', 'Delivery pricing is unavailable. Please contact the restaurant.', $distance);
                }
            }
            usort($slabs, static fn ($left, $right) => (float) ($left['min_km'] ?? 0) <=> (float) ($right['min_km'] ?? 0));
            $previousMax = 0.0;
            foreach ($slabs as $slab) {
                if (! is_array($slab) || ! is_numeric($slab['min_km'] ?? null)
                    || ! is_numeric($slab['max_km'] ?? null) || ! is_numeric($slab['charge'] ?? null)
                    || abs((float) $slab['min_km'] - $previousMax) > 0.000001
                    || (float) $slab['max_km'] <= (float) $slab['min_km'] || (float) $slab['charge'] < 0) {
                    return $this->failed('DELIVERY_PRICE_UNAVAILABLE', 'Delivery pricing is unavailable. Please contact the restaurant.', $distance);
                }
                $previousMax = (float) $slab['max_km'];
            }
            foreach ($slabs as $index => $slab) {
                $min = (float) ($slab['min_km'] ?? -1);
                $max = (float) ($slab['max_km'] ?? -1);
                if ($min >= 0 && $max > $min && $distance <= $max + 0.000001
                    && ($index === 0 ? $distance >= $min : $distance > $min)) {
                    $base = (string) ($slab['charge'] ?? 0);
                    $rule = 'slab:'.$min.'-'.$max;
                    break;
                }
            }
        } elseif ($method === 'base_plus_km') {
            foreach (['base_delivery_charge', 'per_additional_km_charge'] as $key) {
                if (! is_numeric($settings[$key] ?? 0) || ! is_finite((float) ($settings[$key] ?? 0)) || (float) ($settings[$key] ?? 0) < 0) {
                    return $this->failed('DELIVERY_PRICE_UNAVAILABLE', 'Delivery pricing is unavailable. Please contact the restaurant.', $distance);
                }
            }
            $included = max(0, (float) ($settings['base_distance_km'] ?? 0));
            $base = bcadd(DeliveryMoney::decimal($settings['base_delivery_charge'] ?? 0),
                bcmul((string) ceil(max(0, $distance - $included - 0.000001)),
                    DeliveryMoney::decimal($settings['per_additional_km_charge'] ?? 0), 10), 10);
            $rule = 'base_plus_started_km';
        }
        if ($base === null || ! is_finite((float) $base) || $base < 0) {
            return $this->failed('DELIVERY_PRICE_UNAVAILABLE', 'Delivery pricing is unavailable for this address. Please contact the restaurant.', $distance);
        }
        $currency = $branch->currency ?: 'INR';
        $base = DeliveryMoney::amount(DeliveryMoney::minor($base, $currency), $currency);
        $gstEnabled = filter_var($settings['delivery_customer_fee_gst_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $gstRate = $gstEnabled && is_numeric($settings['delivery_customer_fee_gst_rate'] ?? null)
            ? min(100, max(0, (float) $settings['delivery_customer_fee_gst_rate'])) : 0.0;
        $gst = DeliveryMoney::amount(DeliveryMoney::minor(
            bcmul(DeliveryMoney::decimal($base), bcdiv(DeliveryMoney::decimal($gstRate), '100', 10), 10), $currency
        ), $currency);
        $gross = DeliveryMoney::add($base, $gst, $currency);

        $matchedFreeRule = null;
        foreach ((array) ($settings['delivery_free_rules'] ?? []) as $freeRule) {
            if (! is_array($freeRule) || ! ($freeRule['active'] ?? false)
                || ! is_numeric($freeRule['minimum_order_amount'] ?? null)
                || ! is_numeric($freeRule['maximum_distance_km'] ?? null)) {
                continue;
            }
            if ($subtotal >= (float) $freeRule['minimum_order_amount']
                && $distance <= (float) $freeRule['maximum_distance_km'] + 0.000001) {
                $matchedFreeRule = (string) ($freeRule['name'] ?? 'Free delivery');
                break;
            }
        }
        $threshold = $settings['free_delivery_above_order_amount'] ?? null;
        $legacyFree = $matchedFreeRule === null && $threshold !== null && is_numeric($threshold)
            && $subtotal >= (float) $threshold;
        $free = $matchedFreeRule !== null || $legacyFree;

        return [
            'serviceable' => true,
            'distance_km' => round($distance, 3),
            'base_delivery_fee' => $base,
            'delivery_fee_before_gst' => $base,
            'delivery_fee_gst_rate' => $gstRate,
            'delivery_fee_gst' => $free ? 0.0 : $gst,
            'delivery_fee' => $free ? 0.0 : $gross,
            'free_delivery' => $free,
            'matched_free_delivery_rule' => $matchedFreeRule,
            'pricing_rule' => $matchedFreeRule !== null ? $rule.':free_rule:'.$matchedFreeRule
                : ($legacyFree ? $rule.':free_above_'.(float) $threshold : $rule),
            'failure_code' => null,
            'message' => null,
        ];
    }

    private function failed(string $code, string $message, ?float $distance = null, bool $providerPricingFallbackAllowed = false): array
    {
        return ['serviceable' => false, 'distance_km' => $distance === null ? null : round($distance, 3),
            'base_delivery_fee' => null, 'delivery_fee' => null, 'free_delivery' => false,
            'pricing_rule' => null, 'failure_code' => $code, 'message' => $message,
            'provider_pricing_fallback_allowed' => $providerPricingFallbackAllowed];
    }
}
