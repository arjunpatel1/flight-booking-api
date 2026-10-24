<?php

namespace Modules\Flight\Services\Supplier\DTOs;

class SupplierSearchParams
{
    public function __construct(
        public string $origin,
        public string $destination,
        public string $departureDate,
        public ?string $returnDate = null,
        public string $cabinClass = 'ECONOMY',
        public int $adults = 1,
        public int $children = 0,
        public int $infants = 0,
        public ?string $airlineCode = null,
        public bool $directOnly = false,
        public string $currency = 'INR',
        public array $options = [],
    ) {}

    public function toArray(): array
    {
        return [
            'origin'         => $this->origin,
            'destination'    => $this->destination,
            'departure_date' => $this->departureDate,
            'return_date'    => $this->returnDate,
            'cabin_class'    => $this->cabinClass,
            'adults'         => $this->adults,
            'children'       => $this->children,
            'infants'        => $this->infants,
            'airline_code'   => $this->airlineCode,
            'direct_only'    => $this->directOnly,
            'currency'       => $this->currency,
            'options'        => $this->options,
        ];
    }
}
