<?php

namespace Modules\Flight\Services\Supplier\Contracts;

use Modules\Flight\Services\Supplier\DTOs\SupplierAncillaryResponse;
use Modules\Flight\Services\Supplier\DTOs\SupplierAvailabilityResponse;
use Modules\Flight\Services\Supplier\DTOs\SupplierBookingResponse;
use Modules\Flight\Services\Supplier\DTOs\SupplierBookRequest;
use Modules\Flight\Services\Supplier\DTOs\SupplierBookResponse;
use Modules\Flight\Services\Supplier\DTOs\SupplierCancelResponse;
use Modules\Flight\Services\Supplier\DTOs\SupplierHealthResult;
use Modules\Flight\Services\Supplier\DTOs\SupplierHoldRequest;
use Modules\Flight\Services\Supplier\DTOs\SupplierHoldResponse;
use Modules\Flight\Services\Supplier\DTOs\SupplierPriceResponse;
use Modules\Flight\Services\Supplier\DTOs\SupplierRefundResponse;
use Modules\Flight\Services\Supplier\DTOs\SupplierReissueResponse;
use Modules\Flight\Services\Supplier\DTOs\SupplierSearchParams;
use Modules\Flight\Services\Supplier\DTOs\SupplierSearchResponse;
use Modules\Flight\Services\Supplier\DTOs\SupplierSeatMapResponse;
use Modules\Flight\Services\Supplier\DTOs\SupplierTicketRequest;
use Modules\Flight\Services\Supplier\DTOs\SupplierTicketResponse;

/**
 * Phase 5: Supplier Interface Contract
 *
 * All external supply integrations (GDS, NDC, Airline APIs, Consolidators,
 * Manual suppliers, Contracted Inventory) must implement this standardized contract.
 */
interface SupplierInterface
{
    /** Get unique supplier code (e.g., AMADEUS_GDS, MOCK_SUPPLIER). */
    public function getCode(): string;

    /** Get human-readable supplier name. */
    public function getName(): string;

    /** Check if this adapter supports a given operation. */
    public function supportsOperation(string $operation): bool;

    /** 1. Search flight offers matching criteria. */
    public function search(SupplierSearchParams $params): SupplierSearchResponse;

    /** 2. Re-price and confirm fare breakdown for a selected offer. */
    public function price(string|array $offerReference, array $options = []): SupplierPriceResponse;

    /** 3. Check real-time seat availability for an offer. */
    public function availability(string $offerReference, array $options = []): SupplierAvailabilityResponse;

    /** 4. Hold seats temporarily before payment. */
    public function hold(SupplierHoldRequest $request): SupplierHoldResponse;

    /** 5. Create confirmed booking / PNR. */
    public function book(SupplierBookRequest $request): SupplierBookResponse;

    /** 6. Issue e-tickets for a confirmed booking. */
    public function ticket(SupplierTicketRequest $request): SupplierTicketResponse;

    /** 7. Cancel booking / ticket. */
    public function cancel(string $supplierBookingRef, array $options = []): SupplierCancelResponse;

    /** 8. Process refund for cancelled ticket. */
    public function refund(string $supplierBookingRef, array $options = []): SupplierRefundResponse;

    /** 9. Reissue / date-change ticket. */
    public function reissue(string $supplierBookingRef, array $options = []): SupplierReissueResponse;

    /** 10. Retrieve interactive aircraft seat map. */
    public function seatMap(string $flightReference, array $options = []): SupplierSeatMapResponse;

    /** 11. Retrieve ancillary services (extra baggage, meals, lounge). */
    public function ancillary(string $flightReference, array $options = []): SupplierAncillaryResponse;

    /** 12. Retrieve existing booking details by supplier reference / PNR. */
    public function retrieveBooking(string $supplierBookingRef): SupplierBookingResponse;

    /** Perform connection & health ping check. */
    public function healthCheck(): SupplierHealthResult;
}
