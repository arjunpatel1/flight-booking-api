<?php

namespace Modules\Flight\Services\Supplier\Adapters;

use Illuminate\Support\Str;
use Modules\Flight\Enums\SupplierHealthStatus;
use Modules\Flight\Models\FlightSupplier;
use Modules\Flight\Models\FlightSupplierConfiguration;
use Modules\Flight\Models\FlightSupplierCredential;
use Modules\Flight\Services\Supplier\Contracts\SupplierAdapter;
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
use Modules\Flight\Services\Supplier\Exceptions\SupplierBookingFailedException;
use Modules\Flight\Services\Supplier\Exceptions\SupplierCommunicationException;
use Modules\Flight\Services\Supplier\Exceptions\SupplierTimeoutException;

/**
 * Phase 5: Mock Supplier Adapter
 *
 * Full-fidelity simulator for testing and development.
 * Emulates GDS / NDC / Direct Connect API behaviors, PNR generation,
 * e-ticketing, ancillaries, and seat maps with fault injection capabilities.
 */
class MockSupplierAdapter extends SupplierAdapter
{
    private static bool $simulateTimeout = false;
    private static bool $simulateFailure = false;
    private static ?string $failureMessage = null;
    private static bool $simulateDegraded = false;
    private static float $priceChangeDelta = 0.0;

    public function __construct(
        ?FlightSupplier $supplier = null,
        ?FlightSupplierConfiguration $configuration = null,
        ?FlightSupplierCredential $credentials = null,
    ) {
        if (! $supplier) {
            $supplier = new FlightSupplier([
                'code'            => 'MOCK_SUPPLIER',
                'name'            => 'Mock Flight GDS / NDC Provider',
                'type'            => 'gds',
                'adapter_class'   => self::class,
                'environment'     => 'test',
                'health_status'   => SupplierHealthStatus::ACTIVE,
                'is_active'       => true,
                'timeout_seconds' => 15,
            ]);
        }

        parent::__construct($supplier, $configuration, $credentials);
    }

    // ── Simulation Controls ───────────────────────────────────────────────────

    public static function setSimulateTimeout(bool $enabled): void
    {
        self::$simulateTimeout = $enabled;
    }

    public static function setSimulateFailure(bool $enabled, ?string $message = 'Provider simulated error'): void
    {
        self::$simulateFailure = $enabled;
        self::$failureMessage = $message;
    }

    public static function setSimulateDegraded(bool $enabled): void
    {
        self::$simulateDegraded = $enabled;
    }

    public static function setPriceChangeDelta(float $delta): void
    {
        self::$priceChangeDelta = $delta;
    }

    public static function resetSimulation(): void
    {
        self::$simulateTimeout = false;
        self::$simulateFailure = false;
        self::$failureMessage = null;
        self::$simulateDegraded = false;
        self::$priceChangeDelta = 0.0;
    }

    protected function checkFaultInjection(string $operation): void
    {
        if (self::$simulateTimeout) {
            throw new SupplierTimeoutException($this->getCode(), $operation, $this->getTimeoutSeconds());
        }

        if (self::$simulateFailure) {
            throw new SupplierCommunicationException(
                self::$failureMessage ?? "Simulated failure for {$operation}",
                $this->getCode(),
                $operation
            );
        }
    }

    // ── 1. Search ─────────────────────────────────────────────────────────────

    public function search(SupplierSearchParams $params): SupplierSearchResponse
    {
        return $this->executeOperation('search', function () use ($params) {
            $this->checkFaultInjection('search');

            $airlines = [
                ['code' => 'AI', 'name' => 'Air India', 'prefix' => 'AI'],
                ['code' => '6E', 'name' => 'IndiGo', 'prefix' => '6E'],
                ['code' => 'UK', 'name' => 'Vistara', 'prefix' => 'UK'],
            ];

            if ($params->airlineCode) {
                $airlines = array_filter($airlines, fn ($a) => $a['code'] === strtoupper($params->airlineCode));
                if (empty($airlines)) {
                    $airlines = [['code' => strtoupper($params->airlineCode), 'name' => $params->airlineCode, 'prefix' => $params->airlineCode]];
                }
            }

            $offers = [];
            $offerIndex = 1;

            foreach ($airlines as $airline) {
                $basePrice = 4500.0 + ($offerIndex * 350.0);
                $tax = 850.0;
                $fee = 200.0;
                $total = $basePrice + $tax + $fee;

                $offers[] = [
                    'offer_reference' => "MOCK-OFFER-{$params->origin}-{$params->destination}-{$offerIndex}",
                    'airline' => [
                        'code' => $airline['code'],
                        'name' => $airline['name'],
                    ],
                    'flight_number'   => $airline['prefix'] . (100 + $offerIndex),
                    'origin'          => strtoupper($params->origin),
                    'destination'     => strtoupper($params->destination),
                    'departure_time'  => "{$params->departureDate} 08:30:00",
                    'arrival_time'    => "{$params->departureDate} 10:45:00",
                    'duration'        => '2h 15m',
                    'cabin_class'     => strtoupper($params->cabinClass),
                    'available_seats' => 9,
                    'is_refundable'   => $offerIndex % 2 === 0,
                    'pricing'         => [
                        'base_fare'  => $basePrice,
                        'tax'        => $tax,
                        'fees'       => $fee,
                        'total_fare' => $total,
                        'currency'   => $params->currency,
                    ],
                    'baggage' => [
                        'cabin_kg'   => 7,
                        'checkin_kg' => 15,
                    ],
                ];

                $offerIndex++;
            }

            return new SupplierSearchResponse(
                success:      true,
                supplierCode: $this->getCode(),
                offers:       $offers,
                searchId:     'MOCK-SEARCH-' . Str::upper(Str::random(10)),
                currency:     $params->currency,
            );
        });
    }

    // ── 2. Price ──────────────────────────────────────────────────────────────

    public function price(string|array $offerReference, array $options = []): SupplierPriceResponse
    {
        return $this->executeOperation('price', function () use ($offerReference) {
            $this->checkFaultInjection('price');

            $ref = is_array($offerReference) ? ($offerReference['offer_reference'] ?? 'MOCK-REF') : $offerReference;
            $base = 4500.0 + self::$priceChangeDelta;
            $tax  = 850.0;
            $fee  = 200.0;
            $total = $base + $tax + $fee;

            return new SupplierPriceResponse(
                success:          true,
                supplierCode:     $this->getCode(),
                offerReference:   $ref,
                baseFare:         $base,
                tax:              $tax,
                fees:             $fee,
                totalFare:        $total,
                currency:         'INR',
                isPriceConfirmed: true,
                priceChange:      self::$priceChangeDelta,
                fareBreakdown:    [
                    'base_fare' => $base,
                    'taxes'     => $tax,
                    'surcharges'=> $fee,
                ],
                validUntil:       now()->addMinutes(20)->toIso8601String(),
            );
        });
    }

    // ── 3. Availability ───────────────────────────────────────────────────────

    public function availability(string $offerReference, array $options = []): SupplierAvailabilityResponse
    {
        return $this->executeOperation('availability', function () use ($offerReference) {
            $this->checkFaultInjection('availability');

            return new SupplierAvailabilityResponse(
                success:        true,
                supplierCode:   $this->getCode(),
                offerReference: $offerReference,
                isAvailable:    true,
                availableSeats: 7,
                cabinClass:     'ECONOMY',
                confirmedPrice: 5550.0,
                currency:       'INR',
            );
        });
    }

    // ── 4. Hold ───────────────────────────────────────────────────────────────

    public function hold(SupplierHoldRequest $request): SupplierHoldResponse
    {
        return $this->executeOperation('hold', function () use ($request) {
            $this->checkFaultInjection('hold');

            $pnr = strtoupper(Str::random(6));
            $holdRef = 'HOLD-' . Str::upper(Str::random(8));

            return new SupplierHoldResponse(
                success:         true,
                supplierCode:    $this->getCode(),
                holdReference:   $holdRef,
                supplierPnr:     $pnr,
                heldUntil:       now()->addMinutes($request->holdDurationMinutes)->toIso8601String(),
                guaranteedPrice: 5550.0,
                currency:        'INR',
            );
        });
    }

    // ── 5. Book ───────────────────────────────────────────────────────────────

    public function book(SupplierBookRequest $request): SupplierBookResponse
    {
        return $this->executeOperation('book', function () use ($request) {
            $this->checkFaultInjection('book');

            if (empty($request->passengers)) {
                throw new SupplierBookingFailedException('At least one passenger is required for booking', $this->getCode(), 'book');
            }

            $pnr = strtoupper(Str::random(6));
            $bookingRef = 'BK-MOCK-' . Str::upper(Str::random(8));

            return new SupplierBookResponse(
                success:            true,
                supplierCode:       $this->getCode(),
                bookingReference:   $bookingRef,
                supplierPnr:        $pnr,
                status:             'CONFIRMED',
                totalAmount:        5550.0 * count($request->passengers),
                currency:           'INR',
                ticketingTimeLimit: now()->addHours(2)->toIso8601String(),
                passengers:         $request->passengers,
            );
        });
    }

    // ── 6. Ticket ─────────────────────────────────────────────────────────────

    public function ticket(SupplierTicketRequest $request): SupplierTicketResponse
    {
        return $this->executeOperation('ticket', function () use ($request) {
            $this->checkFaultInjection('ticket');

            $ticketNumbers = [
                '098-' . mt_rand(1000000000, 9999999999),
            ];

            return new SupplierTicketResponse(
                success:          true,
                supplierCode:     $this->getCode(),
                bookingReference: $request->bookingReference,
                supplierPnr:      $request->supplierPnr,
                ticketNumbers:    $ticketNumbers,
                status:           'ISSUED',
                issuedAt:         now()->toIso8601String(),
            );
        });
    }

    // ── 7. Cancel ─────────────────────────────────────────────────────────────

    public function cancel(string $supplierBookingRef, array $options = []): SupplierCancelResponse
    {
        return $this->executeOperation('cancel', function () use ($supplierBookingRef) {
            $this->checkFaultInjection('cancel');

            return new SupplierCancelResponse(
                success:          true,
                supplierCode:     $this->getCode(),
                bookingReference: $supplierBookingRef,
                supplierPnr:      'MOCKPN',
                isCancelled:      true,
                cancellationFee:  1000.0,
                refundableAmount: 4550.0,
                currency:         'INR',
            );
        });
    }

    // ── 8. Refund ─────────────────────────────────────────────────────────────

    public function refund(string $supplierBookingRef, array $options = []): SupplierRefundResponse
    {
        return $this->executeOperation('refund', function () use ($supplierBookingRef) {
            $this->checkFaultInjection('refund');

            return new SupplierRefundResponse(
                success:             true,
                supplierCode:        $this->getCode(),
                bookingReference:    $supplierBookingRef,
                refundReference:     'RF-MOCK-' . Str::upper(Str::random(8)),
                refundAmount:        4550.0,
                cancellationPenalty: 1000.0,
                currency:            'INR',
                status:              'PROCESSED',
            );
        });
    }

    // ── 9. Reissue ────────────────────────────────────────────────────────────

    public function reissue(string $supplierBookingRef, array $options = []): SupplierReissueResponse
    {
        return $this->executeOperation('reissue', function () use ($supplierBookingRef) {
            $this->checkFaultInjection('reissue');

            return new SupplierReissueResponse(
                success:          true,
                supplierCode:     $this->getCode(),
                bookingReference: $supplierBookingRef,
                supplierPnr:      'MOCKPN',
                newTicketNumbers: ['098-' . mt_rand(1000000000, 9999999999)],
                fareDifference:   500.0,
                penaltyFee:       750.0,
                totalCharged:     1250.0,
                currency:         'INR',
            );
        });
    }

    // ── 10. Seat Map ──────────────────────────────────────────────────────────

    public function seatMap(string $flightReference, array $options = []): SupplierSeatMapResponse
    {
        return $this->executeOperation('seatMap', function () use ($flightReference) {
            $this->checkFaultInjection('seatMap');

            $rows = [];
            for ($r = 1; $r <= 10; $r++) {
                $seats = [];
                foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
                    $seats[] = [
                        'seat'         => "{$r}{$col}",
                        'is_available' => ! ($r === 3 && in_array($col, ['A', 'F'])),
                        'is_window'    => in_array($col, ['A', 'F']),
                        'is_aisle'     => in_array($col, ['C', 'D']),
                        'is_extra_leg' => $r === 1 || $r === 12,
                        'fee'          => $r === 1 ? 500.0 : ($r <= 5 ? 200.0 : 0.0),
                    ];
                }
                $rows[] = ['row_number' => $r, 'seats' => $seats];
            }

            return new SupplierSeatMapResponse(
                success:         true,
                supplierCode:    $this->getCode(),
                flightReference: $flightReference,
                cabins:          [
                    ['cabin_class' => 'ECONOMY', 'layout' => '3-3', 'rows' => $rows],
                ],
            );
        });
    }

    // ── 11. Ancillary ─────────────────────────────────────────────────────────

    public function ancillary(string $flightReference, array $options = []): SupplierAncillaryResponse
    {
        return $this->executeOperation('ancillary', function () use ($flightReference) {
            $this->checkFaultInjection('ancillary');

            return new SupplierAncillaryResponse(
                success:         true,
                supplierCode:    $this->getCode(),
                flightReference: $flightReference,
                baggageOptions:  [
                    ['code' => 'BAG_5KG', 'name' => 'Extra 5 KG Baggage', 'weight_kg' => 5, 'price' => 1500.0],
                    ['code' => 'BAG_10KG', 'name' => 'Extra 10 KG Baggage', 'weight_kg' => 10, 'price' => 2800.0],
                ],
                mealOptions:     [
                    ['code' => 'VGML', 'name' => 'Vegetarian Hindu Meal', 'price' => 350.0],
                    ['code' => 'NVML', 'name' => 'Non-Vegetarian Meal', 'price' => 450.0],
                    ['code' => 'JNML', 'name' => 'Jain Vegetarian Meal', 'price' => 350.0],
                ],
                loungeOptions:   [
                    ['code' => 'LNG_DOM', 'name' => 'Domestic Airport Lounge Access', 'price' => 1200.0],
                ],
            );
        });
    }

    // ── 12. Retrieve Booking ──────────────────────────────────────────────────

    public function retrieveBooking(string $supplierBookingRef): SupplierBookingResponse
    {
        return $this->executeOperation('retrieveBooking', function () use ($supplierBookingRef) {
            $this->checkFaultInjection('retrieveBooking');

            return new SupplierBookingResponse(
                success:          true,
                supplierCode:     $this->getCode(),
                bookingReference: $supplierBookingRef,
                supplierPnr:      'MOCKPN',
                status:           'CONFIRMED',
                passengers:       [
                    ['first_name' => 'John', 'last_name' => 'Doe', 'ticket_number' => '098-1234567890'],
                ],
                itinerary:        [
                    ['flight_number' => 'AI101', 'origin' => 'DEL', 'destination' => 'BOM', 'departure_time' => '08:30:00'],
                ],
                tickets:          ['098-1234567890'],
            );
        });
    }

    // ── Health Check ──────────────────────────────────────────────────────────

    public function healthCheck(): SupplierHealthResult
    {
        if (self::$simulateFailure) {
            return new SupplierHealthResult(
                supplierCode: $this->getCode(),
                status:       SupplierHealthStatus::FAILED,
                latencyMs:    5000,
                endpoint:     'https://mock.gds.flight.api/v1/ping',
                isHealthy:    false,
                errorMessage: self::$failureMessage ?? 'Simulated supplier connection failed',
            );
        }

        if (self::$simulateDegraded) {
            return new SupplierHealthResult(
                supplierCode: $this->getCode(),
                status:       SupplierHealthStatus::DEGRADED,
                latencyMs:    1850,
                endpoint:     'https://mock.gds.flight.api/v1/ping',
                isHealthy:    true,
                errorMessage: 'High latency detected: 1850ms',
            );
        }

        return new SupplierHealthResult(
            supplierCode: $this->getCode(),
            status:       SupplierHealthStatus::ACTIVE,
            latencyMs:    28,
            endpoint:     'https://mock.gds.flight.api/v1/ping',
            isHealthy:    true,
        );
    }
}
