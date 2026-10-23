<?php

namespace Modules\Flight\Services\Supplier;

use Modules\Flight\Enums\SupplierMappingType;
use Modules\Flight\Models\FlightAirline;
use Modules\Flight\Models\FlightFare;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Models\FlightSupplier;
use Modules\Flight\Models\FlightSupplierMapping;

/**
 * Phase 5: Supplier Mapping Service
 *
 * Normalizes supplier-specific codes and identifiers into core Flight domain entities,
 * and tracks Supplier Reference, Supplier PNR, and Ticket Numbers.
 */
class SupplierMappingService
{
    /**
     * Map a supplier's airline code to internal FlightAirline.
     */
    public function mapAirline(FlightSupplier $supplier, string $supplierAirlineCode): ?FlightAirline
    {
        // 1. Check explicit mapping table
        $mapping = FlightSupplierMapping::where('flight_supplier_id', $supplier->id)
            ->where('mapping_type', SupplierMappingType::AIRLINE->value)
            ->where('supplier_code', strtoupper($supplierAirlineCode))
            ->first();

        if ($mapping && $mapping->internal_id) {
            return FlightAirline::find($mapping->internal_id);
        }

        // 2. Fallback to direct IATA or ICAO code match
        return FlightAirline::where('iata_code', strtoupper($supplierAirlineCode))
            ->orWhere('icao_code', strtoupper($supplierAirlineCode))
            ->first();
    }

    /**
     * Map a supplier's flight number to internal FlightInventory.
     */
    public function mapFlight(FlightSupplier $supplier, string $supplierFlightNumber): ?FlightInventory
    {
        $mapping = FlightSupplierMapping::where('flight_supplier_id', $supplier->id)
            ->where('mapping_type', SupplierMappingType::FLIGHT->value)
            ->where('supplier_code', strtoupper($supplierFlightNumber))
            ->first();

        if ($mapping && $mapping->internal_id) {
            return FlightInventory::find($mapping->internal_id);
        }

        return FlightInventory::where('flight_number', strtoupper($supplierFlightNumber))->first();
    }

    /**
     * Map a supplier's fare basis/class code to internal FlightFare.
     */
    public function mapFare(FlightSupplier $supplier, string $supplierFareBasis): ?FlightFare
    {
        $mapping = FlightSupplierMapping::where('flight_supplier_id', $supplier->id)
            ->where('mapping_type', SupplierMappingType::FARE->value)
            ->where('supplier_code', strtoupper($supplierFareBasis))
            ->first();

        if ($mapping && $mapping->internal_id) {
            return FlightFare::find($mapping->internal_id);
        }

        return null;
    }

    /**
     * Map an external inventory code to internal FlightInventory.
     */
    public function mapInventory(FlightSupplier $supplier, string $supplierInventoryCode): ?FlightInventory
    {
        $mapping = FlightSupplierMapping::where('flight_supplier_id', $supplier->id)
            ->where('mapping_type', SupplierMappingType::INVENTORY->value)
            ->where('supplier_code', $supplierInventoryCode)
            ->first();

        if ($mapping && $mapping->internal_id) {
            return FlightInventory::find($mapping->internal_id);
        }

        return FlightInventory::where('inventory_id', $supplierInventoryCode)->first();
    }

    /**
     * Record a supplier booking mapping (Supplier Reference, Supplier PNR, Supplier Ticket Number).
     */
    public function recordBookingMapping(
        FlightSupplier $supplier,
        ?int $tenantId,
        string $internalRef,
        string $supplierReference,
        string $supplierPnr,
        ?string $supplierTicketNumber = null,
        array $metadata = [],
    ): FlightSupplierMapping {
        return FlightSupplierMapping::create([
            'flight_supplier_id'     => $supplier->id,
            'tenant_id'              => $tenantId,
            'mapping_type'           => SupplierMappingType::REFERENCE->value,
            'internal_code'          => $internalRef,
            'supplier_code'          => $supplierReference,
            'supplier_reference'     => $supplierReference,
            'supplier_pnr'           => strtoupper($supplierPnr),
            'supplier_ticket_number' => $supplierTicketNumber,
            'metadata'               => $metadata,
        ]);
    }

    /**
     * Find booking mapping by supplier PNR.
     */
    public function findBookingBySupplierPnr(string $pnr): ?FlightSupplierMapping
    {
        return FlightSupplierMapping::where('supplier_pnr', strtoupper($pnr))->latest()->first();
    }

    /**
     * Find booking mapping by supplier ticket number.
     */
    public function findBookingByTicketNumber(string $ticketNumber): ?FlightSupplierMapping
    {
        return FlightSupplierMapping::where('supplier_ticket_number', $ticketNumber)->latest()->first();
    }

    /**
     * Create or update an entity mapping.
     */
    public function setMapping(
        FlightSupplier $supplier,
        SupplierMappingType $type,
        string $supplierCode,
        ?int $internalId = null,
        ?string $internalCode = null,
        ?int $tenantId = null,
    ): FlightSupplierMapping {
        return FlightSupplierMapping::updateOrCreate(
            [
                'flight_supplier_id' => $supplier->id,
                'mapping_type'       => $type->value,
                'supplier_code'      => $supplierCode,
            ],
            [
                'tenant_id'     => $tenantId,
                'internal_id'   => $internalId,
                'internal_code' => $internalCode,
            ]
        );
    }
}
