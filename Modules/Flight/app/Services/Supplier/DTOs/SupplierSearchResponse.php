<?php

namespace Modules\Flight\Services\Supplier\DTOs;

class SupplierSearchResponse
{
    public function __construct(
        public bool $success,
        public string $supplierCode,
        public array $offers = [],
        public ?string $searchId = null,
        public string $currency = 'INR',
        public ?string $timestamp = null,
        public ?array $rawResponse = null,
        public ?string $errorMessage = null,
    ) {
        $this->timestamp ??= now()->toIso8601String();
    }

    /**
     * Deduplicate flight offers across suppliers, keeping the offer with the lowest total_fare.
     */
    public function deduplicateOffers(): self
    {
        $unique = [];
        foreach ($this->offers as $offer) {
            $key = ($offer['airline_code'] ?? '') . '-' .
                   ($offer['flight_number'] ?? '') . '-' .
                   ($offer['departure_time'] ?? '') . '-' .
                   ($offer['destination'] ?? '') . '-' .
                   ($offer['cabin_class'] ?? '');

            if (! isset($unique[$key]) || ($offer['total_fare'] ?? PHP_INT_MAX) < ($unique[$key]['total_fare'] ?? PHP_INT_MAX)) {
                $unique[$key] = $offer;
            }
        }

        $this->offers = array_values($unique);
        return $this;
    }
}
