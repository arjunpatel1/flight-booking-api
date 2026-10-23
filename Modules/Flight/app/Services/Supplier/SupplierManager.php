<?php

namespace Modules\Flight\Services\Supplier;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Flight\Enums\SupplierHealthStatus;
use Modules\Flight\Enums\SupplierType;
use Modules\Flight\Models\FlightSupplier;
use Modules\Flight\Services\Supplier\Adapters\MockSupplierAdapter;
use Modules\Flight\Services\Supplier\Contracts\SupplierInterface;
use Modules\Flight\Services\Supplier\DTOs\SupplierAncillaryResponse;
use Modules\Flight\Services\Supplier\DTOs\SupplierAvailabilityResponse;
use Modules\Flight\Services\Supplier\DTOs\SupplierBookingResponse;
use Modules\Flight\Services\Supplier\DTOs\SupplierBookRequest;
use Modules\Flight\Services\Supplier\DTOs\SupplierBookResponse;
use Modules\Flight\Services\Supplier\DTOs\SupplierCancelResponse;
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
use Modules\Flight\Services\Supplier\Exceptions\SupplierException;
use Throwable;

/**
 * Phase 5: Supplier Manager
 *
 * Driver-based manager for external flight suppliers.
 * Provides adapter resolution, fallback chaining, circuit-breaking health checks,
 * and proxy methods for standardized flight operations.
 */
class SupplierManager
{
    /** Resolved adapter instances keyed by supplier code. */
    protected array $adapters = [];

    /** Custom driver factories. */
    protected array $customDrivers = [];

    public function __construct(
        protected SupplierHealthService $healthService,
        protected SupplierMappingService $mappingService,
    ) {}

    /**
     * Resolve a supplier adapter by its unique supplier code or model.
     */
    public function supplier(string|FlightSupplier|null $supplier = null): SupplierInterface
    {
        if ($supplier instanceof FlightSupplier) {
            $code = $supplier->code;
            $model = $supplier;
        } elseif (is_string($supplier) && ! empty($supplier)) {
            $code = strtoupper($supplier);
            $model = FlightSupplier::where('code', $code)->with(['configuration', 'credentials'])->first();
        } else {
            return $this->default();
        }

        if (isset($this->adapters[$code])) {
            return $this->adapters[$code];
        }

        return $this->adapters[$code] = $this->createAdapter($code, $model);
    }

    /**
     * Resolve the default active supplier.
     */
    public function default(): SupplierInterface
    {
        $defaultModel = FlightSupplier::active()
            ->orderBy('priority', 'desc')
            ->with(['configuration', 'credentials'])
            ->first();

        if ($defaultModel) {
            return $this->supplier($defaultModel);
        }

        // Fallback to MockSupplierAdapter if no DB supplier exists
        if (! isset($this->adapters['MOCK_SUPPLIER'])) {
            $this->adapters['MOCK_SUPPLIER'] = new MockSupplierAdapter();
        }

        return $this->adapters['MOCK_SUPPLIER'];
    }

    /**
     * Register a custom driver resolver.
     */
    public function extend(string $type, Closure $callback): self
    {
        $this->customDrivers[strtolower($type)] = $callback;
        return $this;
    }

    /**
     * Create an adapter instance based on model or driver type.
     */
    protected function createAdapter(string $code, ?FlightSupplier $model): SupplierInterface
    {
        if (! $model) {
            // Check custom drivers
            if (isset($this->customDrivers[strtolower($code)])) {
                return $this->customDrivers[strtolower($code)]();
            }

            // Return mock adapter for mock codes
            if (str_starts_with($code, 'MOCK')) {
                return new MockSupplierAdapter();
            }

            throw new InvalidArgumentException("Flight supplier [{$code}] not found in database or registered drivers.");
        }

        if (! $model->relationLoaded('configuration') || ! $model->relationLoaded('credentials')) {
            $model->loadMissing(['configuration', 'credentials']);
        }

        $type = $model->type instanceof SupplierType ? $model->type->value : (string) $model->type;

        // Check custom driver for this supplier type
        if (isset($this->customDrivers[$type])) {
            return $this->customDrivers[$type]($model);
        }

        // Check adapter class
        $adapterClass = $model->adapter_class;
        if (! empty($adapterClass) && class_exists($adapterClass)) {
            return new $adapterClass($model, $model->configuration, $model->credentials);
        }

        // Default mock adapter fallback
        return new MockSupplierAdapter($model, $model->configuration, $model->credentials);
    }

    /**
     * Execute an operation with automatic fallback if primary supplier fails.
     *
     * @param callable(SupplierInterface): mixed $operation
     * @throws SupplierException if all candidate suppliers fail
     */
    public function executeWithFallback(callable $operation, ?array $preferredCodes = null): mixed
    {
        $candidates = $this->getCandidateSuppliers($preferredCodes);
        $lastException = null;

        foreach ($candidates as $supplierModel) {
            if ($supplierModel->health_status === SupplierHealthStatus::FAILED) {
                Log::info("Skipping FAILED supplier [{$supplierModel->code}] during fallback execution");
                continue;
            }

            try {
                $adapter = $this->supplier($supplierModel);
                $start = microtime(true);

                $result = $operation($adapter);

                $latencyMs = (int) round((microtime(true) - $start) * 1000);
                $this->healthService->recordSuccess($supplierModel, $latencyMs);

                return $result;
            } catch (Throwable $e) {
                $lastException = $e;
                $this->healthService->recordFailure($supplierModel, $e->getMessage());

                Log::warning("Supplier [{$supplierModel->code}] failed in fallback chain: {$e->getMessage()}. Trying next...");
            }
        }

        throw new SupplierException(
            "All candidate suppliers failed: " . ($lastException?->getMessage() ?? 'No operational supplier'),
            'FALLBACK_FAILED',
            'fallback_chain',
            ['previous' => $lastException?->getMessage()],
            503,
            $lastException
        );
    }

    /**
     * Get candidate suppliers ordered by operational status and priority.
     */
    protected function getCandidateSuppliers(?array $preferredCodes = null): Collection
    {
        $query = FlightSupplier::active()->with(['configuration', 'credentials'])->orderBy('priority', 'desc');

        if (! empty($preferredCodes)) {
            $query->whereIn('code', array_map('strtoupper', $preferredCodes));
        }

        $suppliers = $query->get();

        if ($suppliers->isEmpty()) {
            // Return dummy collection with mock supplier if table is empty
            $dummy = new FlightSupplier([
                'code'          => 'MOCK_SUPPLIER',
                'name'          => 'Mock Flight Provider',
                'type'          => 'gds',
                'adapter_class' => MockSupplierAdapter::class,
                'health_status' => SupplierHealthStatus::ACTIVE,
                'is_active'     => true,
            ]);
            $dummy->setRelation('configuration', null);
            $dummy->setRelation('credentials', collect());

            return collect([$dummy]);
        }

        return $suppliers;
    }

    public function getHealthService(): SupplierHealthService
    {
        return $this->healthService;
    }

    public function getMappingService(): SupplierMappingService
    {
        return $this->mappingService;
    }

    // ── Standardized Operations Proxies ───────────────────────────────────────

    /**
     * Search across multiple active suppliers and deduplicate identical flights.
     */
    public function searchMulti(SupplierSearchParams $params, ?array $supplierCodes = null): SupplierSearchResponse
    {
        $candidates = $this->getCandidateSuppliers($supplierCodes);
        $allOffers = [];
        $errors = [];

        foreach ($candidates as $supplierModel) {
            if ($supplierModel->health_status === SupplierHealthStatus::FAILED) {
                continue;
            }

            try {
                $adapter = $this->supplier($supplierModel);
                $resp = $adapter->search($params);
                if ($resp->success && ! empty($resp->offers)) {
                    foreach ($resp->offers as $offer) {
                        $offer['supplier_code'] = $supplierModel->code;
                        $allOffers[] = $offer;
                    }
                }
            } catch (Throwable $e) {
                $errors[$supplierModel->code] = $e->getMessage();
            }
        }

        $aggregated = new SupplierSearchResponse(
            success: ! empty($allOffers) || empty($errors),
            supplierCode: 'AGGREGATED',
            offers: $allOffers,
            searchId: 'MULTI-' . strtoupper(\Illuminate\Support\Str::random(8)),
        );

        return $aggregated->deduplicateOffers();
    }

    public function search(SupplierSearchParams $params, ?string $supplierCode = null): SupplierSearchResponse
    {
        return $this->supplier($supplierCode)->search($params);
    }

    public function price(string|array $offerRef, array $options = [], ?string $supplierCode = null): SupplierPriceResponse
    {
        return $this->supplier($supplierCode)->price($offerRef, $options);
    }

    public function availability(string $offerRef, array $options = [], ?string $supplierCode = null): SupplierAvailabilityResponse
    {
        return $this->supplier($supplierCode)->availability($offerRef, $options);
    }

    public function hold(SupplierHoldRequest $request, ?string $supplierCode = null): SupplierHoldResponse
    {
        return $this->supplier($supplierCode)->hold($request);
    }

    public function book(SupplierBookRequest $request, ?string $supplierCode = null): SupplierBookResponse
    {
        return $this->supplier($supplierCode)->book($request);
    }

    public function ticket(SupplierTicketRequest $request, ?string $supplierCode = null): SupplierTicketResponse
    {
        return $this->supplier($supplierCode)->ticket($request);
    }

    public function cancel(string $bookingRef, array $options = [], ?string $supplierCode = null): SupplierCancelResponse
    {
        return $this->supplier($supplierCode)->cancel($bookingRef, $options);
    }

    public function refund(string $bookingRef, array $options = [], ?string $supplierCode = null): SupplierRefundResponse
    {
        return $this->supplier($supplierCode)->refund($bookingRef, $options);
    }

    public function reissue(string $bookingRef, array $options = [], ?string $supplierCode = null): SupplierReissueResponse
    {
        return $this->supplier($supplierCode)->reissue($bookingRef, $options);
    }

    public function seatMap(string $flightRef, array $options = [], ?string $supplierCode = null): SupplierSeatMapResponse
    {
        return $this->supplier($supplierCode)->seatMap($flightRef, $options);
    }

    public function ancillary(string $flightRef, array $options = [], ?string $supplierCode = null): SupplierAncillaryResponse
    {
        return $this->supplier($supplierCode)->ancillary($flightRef, $options);
    }

    public function retrieveBooking(string $bookingRef, ?string $supplierCode = null): SupplierBookingResponse
    {
        return $this->supplier($supplierCode)->retrieveBooking($bookingRef);
    }
}
