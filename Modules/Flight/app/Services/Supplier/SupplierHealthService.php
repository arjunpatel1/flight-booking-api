<?php

namespace Modules\Flight\Services\Supplier;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Flight\Enums\SupplierHealthStatus;
use Modules\Flight\Models\FlightSupplier;
use Modules\Flight\Models\FlightSupplierHealthLog;
use Modules\Flight\Services\Supplier\Contracts\SupplierInterface;
use Modules\Flight\Services\Supplier\DTOs\SupplierHealthResult;

/**
 * Phase 5: Supplier Health Monitoring Service
 *
 * Tracks provider connectivity, ping latency, error rates, and manages
 * automatic status transitions between ACTIVE, DEGRADED, and FAILED.
 */
class SupplierHealthService
{
    const DEGRADED_LATENCY_THRESHOLD_MS = 1500;
    const MAX_CONSECUTIVE_FAILURES       = 3;

    /**
     * Perform an active health check on a supplier.
     */
    public function checkHealth(FlightSupplier $supplier, SupplierInterface $adapter): SupplierHealthResult
    {
        if (! $supplier->is_active) {
            return new SupplierHealthResult(
                supplierCode: $supplier->code,
                status:       SupplierHealthStatus::INACTIVE,
                isHealthy:    false,
                errorMessage: 'Supplier is disabled by administrator.',
            );
        }

        try {
            $result = $adapter->healthCheck();

            if (! $result->isHealthy || $result->status === SupplierHealthStatus::FAILED) {
                $this->recordFailure($supplier, $result->errorMessage ?? 'Health check failed', $result->latencyMs);
            } elseif ($result->latencyMs >= self::DEGRADED_LATENCY_THRESHOLD_MS || $result->status === SupplierHealthStatus::DEGRADED) {
                $this->recordDegraded($supplier, $result->latencyMs, $result->errorMessage);
            } else {
                $this->recordSuccess($supplier, $result->latencyMs);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure($supplier, $e->getMessage(), 5000);

            return new SupplierHealthResult(
                supplierCode: $supplier->code,
                status:       SupplierHealthStatus::FAILED,
                latencyMs:    5000,
                isHealthy:    false,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * Record a successful call. Resets failure counter and marks ACTIVE.
     */
    public function recordSuccess(FlightSupplier $supplier, int $latencyMs = 50): void
    {
        $supplier->update([
            'health_status'        => SupplierHealthStatus::ACTIVE->value,
            'consecutive_failures' => 0,
            'last_health_check_at' => now(),
            'last_error_message'   => null,
        ]);

        FlightSupplierHealthLog::create([
            'flight_supplier_id' => $supplier->id,
            'status'             => SupplierHealthStatus::ACTIVE->value,
            'latency_ms'         => $latencyMs,
            'endpoint'           => $supplier->configuration?->endpoint_url,
            'is_healthy'         => true,
            'error_message'      => null,
            'checked_at'         => now(),
        ]);
    }

    /**
     * Record degraded performance (high latency or warning).
     */
    public function recordDegraded(FlightSupplier $supplier, int $latencyMs, ?string $warning = null): void
    {
        $supplier->update([
            'health_status'        => SupplierHealthStatus::DEGRADED->value,
            'last_health_check_at' => now(),
            'last_error_message'   => $warning ?? "High latency detected: {$latencyMs}ms",
        ]);

        FlightSupplierHealthLog::create([
            'flight_supplier_id' => $supplier->id,
            'status'             => SupplierHealthStatus::DEGRADED->value,
            'latency_ms'         => $latencyMs,
            'endpoint'           => $supplier->configuration?->endpoint_url,
            'is_healthy'         => true,
            'error_message'      => $warning ?? "High latency: {$latencyMs}ms",
            'checked_at'         => now(),
        ]);
    }

    /**
     * Record a failed call. Increments failures; triggers FAILED status if threshold exceeded.
     */
    public function recordFailure(FlightSupplier $supplier, string $errorMessage, int $latencyMs = 5000): void
    {
        $newFailures = $supplier->consecutive_failures + 1;
        $newStatus = $newFailures >= self::MAX_CONSECUTIVE_FAILURES
            ? SupplierHealthStatus::FAILED->value
            : SupplierHealthStatus::DEGRADED->value;

        $supplier->update([
            'health_status'        => $newStatus,
            'consecutive_failures' => $newFailures,
            'last_health_check_at' => now(),
            'last_error_message'   => $errorMessage,
        ]);

        FlightSupplierHealthLog::create([
            'flight_supplier_id' => $supplier->id,
            'status'             => $newStatus,
            'latency_ms'         => $latencyMs,
            'endpoint'           => $supplier->configuration?->endpoint_url,
            'is_healthy'         => false,
            'error_message'      => $errorMessage,
            'checked_at'         => now(),
        ]);

        Log::warning("Supplier [{$supplier->code}] failure recorded: {$errorMessage} (Consecutive: {$newFailures})");
    }

    /**
     * Retrieve all active and operational suppliers.
     */
    public function getOperationalSuppliers(): Collection
    {
        return FlightSupplier::operational()->orderBy('priority', 'desc')->get();
    }
}
