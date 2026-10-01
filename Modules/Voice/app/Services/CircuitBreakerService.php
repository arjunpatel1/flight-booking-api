<?php

namespace Modules\Voice\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CircuitBreakerService
{
    private const CACHE_PREFIX = 'circuit_breaker:';
    private const DEFAULT_FAILURE_THRESHOLD = 5;
    private const DEFAULT_TIMEOUT = 60; // seconds
    private const DEFAULT_RETRY_TIMEOUT = 30; // seconds

    /**
     * Execute a function with circuit breaker protection
     */
    public function execute(string $service, callable $callback, int $failureThreshold = null, int $timeout = null): mixed
    {
        $failureThreshold = $failureThreshold ?? self::DEFAULT_FAILURE_THRESHOLD;
        $timeout = $timeout ?? self::DEFAULT_TIMEOUT;

        // Check if circuit is open
        if ($this->isCircuitOpen($service)) {
            Log::warning("Circuit breaker is open for service: {$service}");
            throw new \RuntimeException("Service {$service} is temporarily unavailable due to circuit breaker");
        }

        try {
            $result = $callback();
            
            // Success - reset failure count
            $this->resetFailures($service);
            
            return $result;
        } catch (\Exception $e) {
            // Failure - increment failure count
            $this->incrementFailures($service, $failureThreshold);
            
            Log::error("Service {$service} failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check if circuit is open for a service
     */
    private function isCircuitOpen(string $service): bool
    {
        $cacheKey = $this->getCacheKey($service);
        
        return Cache::has($cacheKey);
    }

    /**
     * Increment failure count for a service
     */
    private function incrementFailures(string $service, int $threshold): void
    {
        $failureKey = $this->getFailureKey($service);
        $failures = Cache::get($failureKey, 0) + 1;
        
        Cache::put($failureKey, $failures, self::DEFAULT_RETRY_TIMEOUT);
        
        // Open circuit if threshold reached
        if ($failures >= $threshold) {
            $this->openCircuit($service);
        }
    }

    /**
     * Reset failure count for a service
     */
    private function resetFailures(string $service): void
    {
        $failureKey = $this->getFailureKey($service);
        Cache::forget($failureKey);
    }

    /**
     * Open circuit for a service
     */
    private function openCircuit(string $service): void
    {
        $cacheKey = $this->getCacheKey($service);
        Cache::put($cacheKey, true, self::DEFAULT_TIMEOUT);
        
        Log::warning("Circuit breaker opened for service: {$service}");
    }

    /**
     * Get cache key for circuit state
     */
    private function getCacheKey(string $service): string
    {
        return self::CACHE_PREFIX . $service . ':open';
    }

    /**
     * Get cache key for failure count
     */
    private function getFailureKey(string $service): string
    {
        return self::CACHE_PREFIX . $service . ':failures';
    }

    /**
     * Get current failure count for a service
     */
    public function getFailureCount(string $service): int
    {
        return Cache::get($this->getFailureKey($service), 0);
    }

    /**
     * Check if circuit is open for a service
     */
    public function isOpen(string $service): bool
    {
        return $this->isCircuitOpen($service);
    }

    /**
     * Manually close circuit for a service
     */
    public function closeCircuit(string $service): void
    {
        $cacheKey = $this->getCacheKey($service);
        Cache::forget($cacheKey);
        $this->resetFailures($service);
        
        Log::info("Circuit breaker manually closed for service: {$service}");
    }
}
