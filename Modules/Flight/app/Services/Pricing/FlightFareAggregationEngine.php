<?php

namespace Modules\Flight\Services\Pricing;

use Illuminate\Support\Collection;
use Modules\Flight\Models\FlightFare;

class FlightFareAggregationEngine
{
    /**
     * Approved 6-Factor Customer-Facing Recommendation Weights:
     * - Price: 40% (0.40)
     * - Duration: 25% (0.25)
     * - Stops: 15% (0.15)
     * - Baggage: 10% (0.10)
     * - Refundability: 5% (0.05)
     * - Changeability: 5% (0.05)
     * Total = 100%
     *
     * COMMERCIAL MARGIN ISOLATION GUARANTEE:
     * Supplier commercial margin, commissions, and platform/tenant markups are strictly
     * excluded from influencing customer-facing recommendations. Recommendations are
     * calculated purely from consumer-centric value factors.
     */
    public const WEIGHT_PRICE = 40.0;
    public const WEIGHT_DURATION = 25.0;
    public const WEIGHT_STOPS = 15.0;
    public const WEIGHT_BAGGAGE = 10.0;
    public const WEIGHT_REFUNDABILITY = 5.0;
    public const WEIGHT_CHANGEABILITY = 5.0;

    /**
     * Aggregate fare options and compute Best Fare outputs:
     * - cheapestFare
     * - fastestFare
     * - bestBaggageFare
     * - recommendedFare
     *
     * @param Collection|array $fares
     * @return array
     */
    public function aggregate($fares): array
    {
        $faresCollection = $fares instanceof Collection ? $fares : collect($fares);

        if ($faresCollection->isEmpty()) {
            return [
                'total_options' => 0,
                'cheapest_fare' => null,
                'fastest_fare' => null,
                'best_baggage_fare' => null,
                'recommended_fare' => null,
                'all_fares' => [],
            ];
        }

        // Map fares with comparative criteria
        $enriched = $faresCollection->map(function ($fare) {
            $inventory = $fare->inventory;
            $durationMinutes = $this->calculateDurationMinutes($inventory);
            $totalBaggageKg = $this->calculateTotalBaggageKg($fare);
            $stops = $this->calculateStops($inventory, $fare);
            $price = (float) $fare->final_customer_price;

            return [
                'fare' => $fare,
                'price' => $price,
                'duration_minutes' => $durationMinutes,
                'stops' => $stops,
                'total_baggage_kg' => $totalBaggageKg,
                'is_refundable' => (bool) $fare->is_refundable,
                'is_changeable' => (bool) $fare->is_changeable,
            ];
        });

        // 1. Cheapest Fare (Lowest customer-facing price)
        $cheapest = $enriched->sortBy('price')->first();

        // 2. Fastest Fare (Shortest duration)
        $fastest = $enriched->sortBy('duration_minutes')->first();

        // 3. Best Baggage Fare (Highest total baggage weight)
        $bestBaggage = $enriched->sortByDesc('total_baggage_kg')->first();

        // 4. Recommended Fare (Approved 6-Factor Customer-Centric Scoring Engine)
        $minPrice = $enriched->min('price');
        $maxPrice = $enriched->max('price');
        $minDuration = $enriched->min('duration_minutes');
        $maxDuration = $enriched->max('duration_minutes');
        $maxBaggage = $enriched->max('total_baggage_kg');

        $scored = $enriched->map(function ($item) use ($minPrice, $maxPrice, $minDuration, $maxDuration, $maxBaggage) {
            // Factor 1: Price (40%) - lower price yields higher score
            if ($maxPrice > $minPrice) {
                $priceScore = self::WEIGHT_PRICE * (1.0 - (($item['price'] - $minPrice) / ($maxPrice - $minPrice)));
            } else {
                $priceScore = self::WEIGHT_PRICE;
            }

            // Factor 2: Duration (25%) - shorter duration yields higher score
            if ($maxDuration > $minDuration) {
                $durationScore = self::WEIGHT_DURATION * (1.0 - (($item['duration_minutes'] - $minDuration) / ($maxDuration - $minDuration)));
            } else {
                $durationScore = self::WEIGHT_DURATION;
            }

            // Factor 3: Stops (15%) - 0 stops = 100%, 1 stop = 50%, 2+ stops = 0%
            $stops = $item['stops'];
            if ($stops === 0) {
                $stopsScore = self::WEIGHT_STOPS;
            } elseif ($stops === 1) {
                $stopsScore = self::WEIGHT_STOPS * 0.5;
            } else {
                $stopsScore = 0.0;
            }

            // Factor 4: Baggage (10%) - higher baggage allowance yields higher score
            if ($maxBaggage > 0) {
                $baggageScore = self::WEIGHT_BAGGAGE * ($item['total_baggage_kg'] / $maxBaggage);
            } else {
                $baggageScore = self::WEIGHT_BAGGAGE;
            }

            // Factor 5: Refundability (5%) - refundable = 5 pts, non-refundable = 0 pts
            $refundScore = $item['is_refundable'] ? self::WEIGHT_REFUNDABILITY : 0.0;

            // Factor 6: Changeability (5%) - changeable = 5 pts, non-changeable = 0 pts
            $changeScore = $item['is_changeable'] ? self::WEIGHT_CHANGEABILITY : 0.0;

            $compositeScore = round($priceScore + $durationScore + $stopsScore + $baggageScore + $refundScore + $changeScore, 2);

            $item['factor_scores'] = [
                'price' => round($priceScore, 2),
                'duration' => round($durationScore, 2),
                'stops' => round($stopsScore, 2),
                'baggage' => round($baggageScore, 2),
                'refundability' => round($refundScore, 2),
                'changeability' => round($changeScore, 2),
            ];
            $item['composite_score'] = $compositeScore;

            return $item;
        });

        $recommended = $scored->sortByDesc('composite_score')->first();

        return [
            'total_options' => $faresCollection->count(),
            'cheapest_fare' => $cheapest ? $cheapest['fare'] : null,
            'fastest_fare' => $fastest ? $fastest['fare'] : null,
            'best_baggage_fare' => $bestBaggage ? $bestBaggage['fare'] : null,
            'recommended_fare' => $recommended ? $recommended['fare'] : null,
            'summary' => [
                'min_price' => $cheapest ? $cheapest['price'] : 0,
                'fastest_duration_minutes' => $fastest ? $fastest['duration_minutes'] : 0,
                'max_baggage_kg' => $bestBaggage ? $bestBaggage['total_baggage_kg'] : 0,
                'recommended_score' => $recommended ? $recommended['composite_score'] : 0,
                'scoring_model' => [
                    'price_weight' => self::WEIGHT_PRICE,
                    'duration_weight' => self::WEIGHT_DURATION,
                    'stops_weight' => self::WEIGHT_STOPS,
                    'baggage_weight' => self::WEIGHT_BAGGAGE,
                    'refundability_weight' => self::WEIGHT_REFUNDABILITY,
                    'changeability_weight' => self::WEIGHT_CHANGEABILITY,
                    'commercial_margin_influence' => false,
                ],
            ],
            'all_fares' => $scored->map(function ($item) use ($cheapest, $fastest, $bestBaggage, $recommended) {
                $f = $item['fare'];
                return [
                    'fare' => $f,
                    'is_cheapest' => $cheapest && $f->id === $cheapest['fare']->id,
                    'is_fastest' => $fastest && $f->id === $fastest['fare']->id,
                    'is_best_baggage' => $bestBaggage && $f->id === $bestBaggage['fare']->id,
                    'is_recommended' => $recommended && $f->id === $recommended['fare']->id,
                    'metrics' => [
                        'price' => $item['price'],
                        'duration_minutes' => $item['duration_minutes'],
                        'stops' => $item['stops'],
                        'total_baggage_kg' => $item['total_baggage_kg'],
                        'factor_scores' => $item['factor_scores'],
                        'composite_score' => $item['composite_score'],
                    ],
                ];
            })->values()->all(),
        ];
    }

    /**
     * Calculate flight duration in minutes from inventory schedule.
     */
    protected function calculateDurationMinutes($inventory): int
    {
        if (! $inventory) {
            return 120;
        }

        try {
            $dep = strtotime($inventory->departure_time);
            $arr = strtotime($inventory->arrival_time);
            if ($dep && $arr) {
                if ($arr < $dep) {
                    $arr += 86400; // Next day arrival
                }
                return max(1, (int) round(($arr - $dep) / 60));
            }
        } catch (\Throwable) {
            // fallback
        }

        return 120;
    }

    /**
     * Calculate total baggage weight in kg.
     */
    protected function calculateTotalBaggageKg(FlightFare $fare): int
    {
        $baggage = $fare->baggage;
        if (is_array($baggage)) {
            $cabin = (int) ($baggage['cabin_kg'] ?? 0);
            $checkin = (int) ($baggage['checkin_kg'] ?? 0);
            return $cabin + $checkin;
        }

        if ($fare->fareFamily && is_array($fare->fareFamily->baggage_allowance)) {
            $cabin = (int) ($fare->fareFamily->baggage_allowance['cabin_kg'] ?? 0);
            $checkin = (int) ($fare->fareFamily->baggage_allowance['checkin_kg'] ?? 0);
            return $cabin + $checkin;
        }

        return 7;
    }

    /**
     * Calculate number of stops.
     */
    protected function calculateStops($inventory, FlightFare $fare): int
    {
        if (isset($fare->metadata['stops'])) {
            return max(0, (int) $fare->metadata['stops']);
        }

        if (isset($inventory?->metadata['stops'])) {
            return max(0, (int) $inventory->metadata['stops']);
        }

        if (isset($inventory?->stops)) {
            return max(0, (int) $inventory->stops);
        }

        return 0; // Default non-stop
    }
}
