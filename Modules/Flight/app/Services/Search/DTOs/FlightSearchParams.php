<?php

namespace Modules\Flight\Services\Search\DTOs;

class FlightSearchParams
{
    public function __construct(
        public string $origin,
        public string $destination,
        public string $departureDate,
        public ?string $returnDate = null,
        public string $tripType = 'one_way', // 'one_way', 'round_trip', 'multi_city'
        public int $adults = 1,
        public int $children = 0,
        public int $infants = 0,
        public string $cabinClass = 'ECONOMY', // ECONOMY, PREMIUM_ECONOMY, BUSINESS, FIRST
        public ?array $airlines = null,
        public ?int $maxStops = null,
        public ?float $minPrice = null,
        public ?float $maxPrice = null,
        public ?string $departureTimeWindow = null, // morning, afternoon, evening, night
        public ?bool $refundableOnly = null,
        public ?bool $baggageIncluded = null,
        public ?string $supplier = null,
        public string $sortBy = 'recommended', // recommended, price_asc, price_desc, duration_asc, departure_asc, departure_desc
        public string $currency = 'INR',
        public array $options = [],
    ) {
        $this->origin = strtoupper(trim($this->origin));
        $this->destination = strtoupper(trim($this->destination));
        $this->cabinClass = strtoupper(trim($this->cabinClass));
        $this->tripType = strtolower(trim($this->tripType));
        $this->sortBy = strtolower(trim($this->sortBy));
    }

    public static function fromArray(array $data): self
    {
        return new self(
            origin:              $data['origin'] ?? '',
            destination:         $data['destination'] ?? '',
            departureDate:       $data['departure_date'] ?? ($data['departureDate'] ?? now()->addDays(7)->format('Y-m-d')),
            returnDate:          $data['return_date'] ?? ($data['returnDate'] ?? null),
            tripType:            $data['trip_type'] ?? ($data['tripType'] ?? 'one_way'),
            adults:              max(1, (int) ($data['adults'] ?? 1)),
            children:            max(0, (int) ($data['children'] ?? 0)),
            infants:             max(0, (int) ($data['infants'] ?? 0)),
            cabinClass:          $data['cabin_class'] ?? ($data['cabinClass'] ?? 'ECONOMY'),
            airlines:            isset($data['airlines']) ? (array) $data['airlines'] : null,
            maxStops:            isset($data['max_stops']) ? (int) $data['max_stops'] : (isset($data['maxStops']) ? (int) $data['maxStops'] : null),
            minPrice:            isset($data['min_price']) ? (float) $data['min_price'] : (isset($data['minPrice']) ? (float) $data['minPrice'] : null),
            maxPrice:            isset($data['max_price']) ? (float) $data['max_price'] : (isset($data['maxPrice']) ? (float) $data['maxPrice'] : null),
            departureTimeWindow: $data['departure_time_window'] ?? ($data['departureTimeWindow'] ?? null),
            refundableOnly:      isset($data['refundable_only']) ? filter_var($data['refundable_only'], FILTER_VALIDATE_BOOLEAN) : (isset($data['refundableOnly']) ? filter_var($data['refundableOnly'], FILTER_VALIDATE_BOOLEAN) : null),
            baggageIncluded:     isset($data['baggage_included']) ? filter_var($data['baggage_included'], FILTER_VALIDATE_BOOLEAN) : (isset($data['baggageIncluded']) ? filter_var($data['baggageIncluded'], FILTER_VALIDATE_BOOLEAN) : null),
            supplier:            $data['supplier'] ?? null,
            sortBy:              $data['sort_by'] ?? ($data['sortBy'] ?? 'recommended'),
            currency:            strtoupper($data['currency'] ?? 'INR'),
            options:             (array) ($data['options'] ?? []),
        );
    }

    public function totalPassengers(): int
    {
        return max(1, $this->adults + $this->children);
    }

    public function isRoundTrip(): bool
    {
        return $this->tripType === 'round_trip' && ! empty($this->returnDate);
    }

    public function toArray(): array
    {
        return [
            'origin'                => $this->origin,
            'destination'           => $this->destination,
            'departure_date'        => $this->departureDate,
            'return_date'           => $this->returnDate,
            'trip_type'             => $this->tripType,
            'adults'                => $this->adults,
            'children'              => $this->children,
            'infants'               => $this->infants,
            'total_passengers'      => $this->totalPassengers(),
            'cabin_class'           => $this->cabinClass,
            'airlines'              => $this->airlines,
            'max_stops'             => $this->maxStops,
            'min_price'             => $this->minPrice,
            'max_price'             => $this->maxPrice,
            'departure_time_window' => $this->departureTimeWindow,
            'refundable_only'       => $this->refundableOnly,
            'baggage_included'      => $this->baggageIncluded,
            'supplier'              => $this->supplier,
            'sort_by'               => $this->sortBy,
            'currency'              => $this->currency,
        ];
    }
}
