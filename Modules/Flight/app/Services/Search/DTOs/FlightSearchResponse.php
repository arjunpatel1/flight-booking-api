<?php

namespace Modules\Flight\Services\Search\DTOs;

class FlightSearchResponse
{
    public function __construct(
        public bool $success,
        public string $searchId,
        public array $params,
        public array $itineraries = [],
        public array $returnItineraries = [],
        public ?array $bestFares = null,
        public array $filtersAvailable = [],
        public array $meta = [],
        public ?string $errorMessage = null,
    ) {}

    public function toArray(): array
    {
        return [
            'success'            => $this->success,
            'search_id'          => $this->searchId,
            'params'             => $this->params,
            'total_itineraries'  => count($this->itineraries),
            'total_return'       => count($this->returnItineraries),
            'itineraries'        => $this->itineraries,
            'return_itineraries' => $this->returnItineraries,
            'best_fares'         => $this->bestFares,
            'filters_available'  => $this->filtersAvailable,
            'meta'               => $this->meta,
            'error_message'      => $this->errorMessage,
        ];
    }
}
