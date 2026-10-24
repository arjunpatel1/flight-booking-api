<?php

namespace Modules\Flight\Services\Commission\DTOs;

class CommissionCalculationRequest
{
    public function __construct(
        public float $baseFare,
        public float $taxes = 0.0,
        public ?float $totalFare = null,
        public int $passengersCount = 1,
        public ?int $supplierId = null,
        public ?string $supplierCode = null,
        public ?int $airlineId = null,
        public ?string $airlineCode = null,
        public ?int $originAirportId = null,
        public ?int $destinationAirportId = null,
        public ?string $cabinClass = null,
        public ?int $fareFamilyId = null,
        public ?string $flightNumber = null,
        public ?int $tenantId = null,
        public ?int $distributorId = null,
        public ?int $agentId = null,
        public ?string $bookingReference = null,
        public string $currency = 'INR',
    ) {
        $this->totalFare ??= $this->baseFare + $this->taxes;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            baseFare:             (float) ($data['base_fare'] ?? 0.0),
            taxes:                (float) ($data['taxes'] ?? 0.0),
            totalFare:            isset($data['total_fare']) ? (float) $data['total_fare'] : null,
            passengersCount:      (int) ($data['passengers_count'] ?? 1),
            supplierId:           isset($data['flight_supplier_id']) ? (int) $data['flight_supplier_id'] : (isset($data['supplier_id']) ? (int) $data['supplier_id'] : null),
            supplierCode:         $data['supplier_code'] ?? null,
            airlineId:            isset($data['airline_id']) ? (int) $data['airline_id'] : null,
            airlineCode:          $data['airline_code'] ?? null,
            originAirportId:      isset($data['origin_airport_id']) ? (int) $data['origin_airport_id'] : null,
            destinationAirportId: isset($data['destination_airport_id']) ? (int) $data['destination_airport_id'] : null,
            cabinClass:           $data['cabin_class'] ?? null,
            fareFamilyId:         isset($data['fare_family_id']) ? (int) $data['fare_family_id'] : null,
            flightNumber:         $data['flight_number'] ?? null,
            tenantId:             isset($data['tenant_id']) ? (int) $data['tenant_id'] : null,
            distributorId:        isset($data['distributor_id']) ? (int) $data['distributor_id'] : null,
            agentId:              isset($data['agent_id']) ? (int) $data['agent_id'] : null,
            bookingReference:     $data['booking_reference'] ?? null,
            currency:             $data['currency'] ?? 'INR',
        );
    }

    public function toArray(): array
    {
        return [
            'base_fare'              => $this->baseFare,
            'taxes'                  => $this->taxes,
            'total_fare'             => $this->totalFare,
            'passengers_count'       => $this->passengersCount,
            'supplier_id'            => $this->supplierId,
            'supplier_code'          => $this->supplierCode,
            'airline_id'             => $this->airlineId,
            'airline_code'           => $this->airlineCode,
            'origin_airport_id'      => $this->originAirportId,
            'destination_airport_id' => $this->destinationAirportId,
            'cabin_class'            => $this->cabinClass,
            'fare_family_id'         => $this->fareFamilyId,
            'flight_number'          => $this->flightNumber,
            'tenant_id'              => $this->tenantId,
            'distributor_id'         => $this->distributorId,
            'agent_id'               => $this->agentId,
            'booking_reference'      => $this->bookingReference,
            'currency'               => $this->currency,
        ];
    }
}
