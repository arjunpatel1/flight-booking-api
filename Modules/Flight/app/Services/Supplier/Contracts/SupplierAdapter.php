<?php

namespace Modules\Flight\Services\Supplier\Contracts;

use Illuminate\Support\Facades\Log;
use Modules\Flight\Enums\SupplierHealthStatus;
use Modules\Flight\Models\FlightSupplier;
use Modules\Flight\Models\FlightSupplierConfiguration;
use Modules\Flight\Models\FlightSupplierCredential;
use Modules\Flight\Services\Supplier\DTOs\SupplierHealthResult;
use Modules\Flight\Services\Supplier\Exceptions\SupplierCommunicationException;
use Modules\Flight\Services\Supplier\Exceptions\SupplierException;
use Modules\Flight\Services\Supplier\Exceptions\SupplierTimeoutException;
use Throwable;

/**
 * Phase 5: Abstract Base Supplier Adapter
 *
 * Provides common infrastructure for all provider adapters:
 * configuration binding, encrypted credentials access, timeout handling,
 * error isolation, and latency monitoring.
 */
abstract class SupplierAdapter implements SupplierInterface
{
    public function __construct(
        protected FlightSupplier $supplier,
        protected ?FlightSupplierConfiguration $configuration = null,
        protected ?FlightSupplierCredential $credentials = null,
    ) {
        $this->configuration ??= $supplier->configuration;
        $this->credentials ??= $supplier->credentials;
    }

    public function getCode(): string
    {
        return $this->supplier->code;
    }

    public function getName(): string
    {
        return $this->supplier->name;
    }

    public function getSupplier(): FlightSupplier
    {
        return $this->supplier;
    }

    public function getConfiguration(): ?FlightSupplierConfiguration
    {
        return $this->configuration;
    }

    public function getCredentials(): ?FlightSupplierCredential
    {
        return $this->credentials;
    }

    public function supportsOperation(string $operation): bool
    {
        if ($this->configuration) {
            return $this->configuration->supportsOperation($operation);
        }

        return true;
    }

    public function getTimeoutSeconds(): int
    {
        if ($this->configuration && $this->configuration->timeout_ms > 0) {
            return (int) ceil($this->configuration->timeout_ms / 1000);
        }

        return $this->supplier->timeout_seconds ?? 15;
    }

    protected ?string $correlationId = null;

    public function setCorrelationId(?string $id): static
    {
        $this->correlationId = $id;
        return $this;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId ??= (string) \Illuminate\Support\Str::uuid();
    }

    public function getMaxRetries(): int
    {
        return 1;
    }

    /**
     * Executes a supplier operation within a standardized error, timeout, and retry boundary.
     * Guarantees that any external failures are trapped, measured, retried if transient, and isolated.
     *
     * @throws SupplierTimeoutException
     * @throws SupplierCommunicationException
     * @throws SupplierException
     */
    protected function executeOperation(string $operation, callable $callback): mixed
    {
        $start = microtime(true);
        $timeout = $this->getTimeoutSeconds();
        $correlationId = $this->getCorrelationId();
        $maxRetries = $this->getMaxRetries();
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                // Execute the operation
                $result = $callback();

                $elapsedMs = (int) round((microtime(true) - $start) * 1000);

                Log::info("Supplier [{$this->getCode()}] operation [{$operation}] succeeded in {$elapsedMs}ms", [
                    'correlation_id' => $correlationId,
                    'attempt'        => $attempt,
                ]);

                return $result;
            } catch (SupplierTimeoutException $e) {
                Log::warning("Supplier [{$this->getCode()}] operation [{$operation}] timed out: {$e->getMessage()}", [
                    'correlation_id' => $correlationId,
                ]);
                throw $e;
            } catch (SupplierException $e) {
                Log::error("Supplier [{$this->getCode()}] operation [{$operation}] business error: {$e->getMessage()}", [
                    'correlation_id' => $correlationId,
                ]);
                throw $e;
            } catch (Throwable $e) {
                $elapsedMs = (int) round((microtime(true) - $start) * 1000);

                // Detect timeout patterns in standard curl / Guzzle / socket errors
                if (str_contains(strtolower($e->getMessage()), 'timed out') || str_contains(strtolower($e->getMessage()), 'timeout')) {
                    Log::warning("Supplier [{$this->getCode()}] operation [{$operation}] timed out after {$elapsedMs}ms", [
                        'correlation_id' => $correlationId,
                    ]);
                    throw new SupplierTimeoutException($this->getCode(), $operation, $timeout);
                }

                // If transient network error and retries remain, retry
                if ($attempt <= $maxRetries) {
                    Log::warning("Supplier [{$this->getCode()}] operation [{$operation}] transient error on attempt {$attempt}. Retrying...", [
                        'correlation_id' => $correlationId,
                        'error'          => $e->getMessage(),
                    ]);
                    usleep(50000); // 50ms pause before retry
                    continue;
                }

                Log::error("Supplier [{$this->getCode()}] operation [{$operation}] network failure after {$attempt} attempts: {$e->getMessage()}", [
                    'correlation_id' => $correlationId,
                    'exception'      => get_class($e),
                ]);

                throw new SupplierCommunicationException(
                    "Communication failed with {$this->getName()}: {$e->getMessage()}",
                    $this->getCode(),
                    $operation,
                    ['raw_error' => $e->getMessage(), 'correlation_id' => $correlationId],
                    (int) $e->getCode(),
                    $e
                );
            }
        }
    }

    /**
     * Default health check implementation. Subclasses can override.
     */
    public function healthCheck(): SupplierHealthResult
    {
        $start = microtime(true);

        try {
            $endpoint = $this->configuration?->endpoint_url;

            return new SupplierHealthResult(
                supplierCode: $this->getCode(),
                status:       SupplierHealthStatus::ACTIVE,
                latencyMs:    (int) round((microtime(true) - $start) * 1000),
                endpoint:     $endpoint,
                isHealthy:    true,
            );
        } catch (Throwable $e) {
            return new SupplierHealthResult(
                supplierCode: $this->getCode(),
                status:       SupplierHealthStatus::FAILED,
                latencyMs:    (int) round((microtime(true) - $start) * 1000),
                endpoint:     $this->configuration?->endpoint_url,
                isHealthy:    false,
                errorMessage: $e->getMessage(),
            );
        }
    }
}
