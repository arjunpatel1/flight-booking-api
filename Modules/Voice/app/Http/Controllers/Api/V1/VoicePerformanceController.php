<?php

namespace Modules\Voice\Http\Controllers\Api\V1;

use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class VoicePerformanceController extends Controller
{
    public function __construct()
    {
    }

    /**
     * Get performance metrics for voice system
     */
    public function getMetrics(): JsonResponse
    {
        $branchId = $this->resolveBranchId();
        if (!$branchId) {
            return $this->branchUnavailableResponse();
        }

        $metrics = [
            'cache_hit_rate' => $this->getCacheHitRate(),
            'database_query_performance' => $this->getQueryPerformance(),
            'voice_announcement_success_rate' => $this->getAnnouncementSuccessRate($branchId),
            'queue_processing_time' => $this->getQueueProcessingTime(),
            'api_response_times' => $this->getApiResponseTimes(),
            'system_health' => $this->getSystemHealth(),
        ];

        return ApiResponse::success($metrics);
    }

    /**
     * Get cache hit rate
     */
    private function getCacheHitRate(): array
    {
        // In a real implementation, this would track cache hits/misses
        return [
            'voice_settings' => [
                'hits' => Cache::get('voice_settings:hits', 0),
                'misses' => Cache::get('voice_settings:misses', 0),
                'hit_rate' => $this->calculateHitRate('voice_settings'),
            ],
            'voice_templates' => [
                'hits' => Cache::get('voice_templates:hits', 0),
                'misses' => Cache::get('voice_templates:misses', 0),
                'hit_rate' => $this->calculateHitRate('voice_templates'),
            ],
        ];
    }

    /**
     * Calculate cache hit rate
     */
    private function calculateHitRate(string $prefix): float
    {
        $hits = Cache::get($prefix . ':hits', 0);
        $misses = Cache::get($prefix . ':misses', 0);
        $total = $hits + $misses;

        return $total > 0 ? round(($hits / $total) * 100, 2) : 0;
    }

    /**
     * Get database query performance
     */
    private function getQueryPerformance(): array
    {
        return [
            'average_query_time' => $this->getAverageQueryTime(),
            'slow_queries' => $this->getSlowQueryCount(),
            'query_cache_hit_rate' => $this->getQueryCacheHitRate(),
        ];
    }

    /**
     * Get average query time
     */
    private function getAverageQueryTime(): float
    {
        return (float) Cache::get('voice_metrics:database:average_query_time_ms', 0);
    }

    /**
     * Get slow query count
     */
    private function getSlowQueryCount(): int
    {
        return (int) Cache::get('voice_metrics:database:slow_queries', 0);
    }

    /**
     * Get query cache hit rate
     */
    private function getQueryCacheHitRate(): float
    {
        return (float) Cache::get('voice_metrics:database:query_cache_hit_rate', 0);
    }

    /**
     * Get voice announcement success rate
     */
    private function getAnnouncementSuccessRate(int $branchId): array
    {
        $total = DB::table('voice_history')
            ->where('branch_id', $branchId)
            ->count();

        $successful = DB::table('voice_history')
            ->where('branch_id', $branchId)
            ->where('success', true)
            ->count();

        return [
            'total' => $total,
            'successful' => $successful,
            'failed' => $total - $successful,
            'success_rate' => $total > 0 ? round(($successful / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Get queue processing time
     */
    private function getQueueProcessingTime(): array
    {
        return [
            'average_processing_time' => (float) Cache::get('voice_metrics:queue:average_processing_time_ms', 0),
            'max_processing_time' => (float) Cache::get('voice_metrics:queue:max_processing_time_ms', 0),
            'min_processing_time' => (float) Cache::get('voice_metrics:queue:min_processing_time_ms', 0),
            'pending_jobs' => DB::table('jobs')->where('queue', 'voice')->count(),
        ];
    }

    /**
     * Get API response times
     */
    private function getApiResponseTimes(): array
    {
        return [
            'get_settings' => [
                'average' => (float) Cache::get('voice_metrics:api:get_settings:average_ms', 0),
                'p95' => (float) Cache::get('voice_metrics:api:get_settings:p95_ms', 0),
                'p99' => (float) Cache::get('voice_metrics:api:get_settings:p99_ms', 0),
            ],
            'get_templates' => [
                'average' => (float) Cache::get('voice_metrics:api:get_templates:average_ms', 0),
                'p95' => (float) Cache::get('voice_metrics:api:get_templates:p95_ms', 0),
                'p99' => (float) Cache::get('voice_metrics:api:get_templates:p99_ms', 0),
            ],
            'save_settings' => [
                'average' => (float) Cache::get('voice_metrics:api:save_settings:average_ms', 0),
                'p95' => (float) Cache::get('voice_metrics:api:save_settings:p95_ms', 0),
                'p99' => (float) Cache::get('voice_metrics:api:save_settings:p99_ms', 0),
            ],
        ];
    }

    /**
     * Get system health status
     */
    private function getSystemHealth(): array
    {
        return [
            'status' => 'healthy',
            'cpu_usage' => $this->getCpuUsage(),
            'memory_usage' => $this->getMemoryUsage(),
            'disk_usage' => $this->getDiskUsage(),
            'queue_status' => $this->getQueueStatus(),
        ];
    }

    /**
     * Get CPU usage
     */
    private function getCpuUsage(): float
    {
        return (float) Cache::get('voice_metrics:system:cpu_usage', 0);
    }

    /**
     * Get memory usage
     */
    private function getMemoryUsage(): float
    {
        return round(memory_get_usage() / 1024 / 1024, 2);
    }

    /**
     * Get disk usage
     */
    private function getDiskUsage(): array
    {
        $total = disk_total_space(base_path()) ?: 0;
        $free = disk_free_space(base_path()) ?: 0;
        $used = max($total - $free, 0);

        return [
            'used' => round($used / 1024 / 1024 / 1024, 2),
            'total' => round($total / 1024 / 1024 / 1024, 2),
            'percentage' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Get queue status
     */
    private function getQueueStatus(): array
    {
        return [
            'voice_queue_size' => DB::table('jobs')->where('queue', 'voice')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'queue_status' => 'running',
        ];
    }

    /**
     * Clear performance cache
     */
    public function clearCache(): JsonResponse
    {
        $cacheService = app(\Modules\Voice\Services\VoiceSettingsCacheService::class);
        $cacheService->invalidateAllCache();

        return ApiResponse::success(null, 'Performance cache cleared');
    }

    /**
     * Warm up cache for performance
     */
    public function warmupCache(): JsonResponse
    {
        $branchId = $this->resolveBranchId();
        if (!$branchId) {
            return $this->branchUnavailableResponse();
        }
        
        $cacheService = app(\Modules\Voice\Services\VoiceSettingsCacheService::class);
        $cacheService->warmupCache([$branchId]);

        return ApiResponse::success(null, 'Cache warmed up successfully');
    }

    private function resolveBranchId(): ?int
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }

        if ($user->assignedToBranch()) {
            return (int) $user->branch_id;
        }

        return $user->effective_branch?->id ? (int) $user->effective_branch->id : null;
    }

    private function branchUnavailableResponse(): JsonResponse
    {
        return ApiResponse::errors(
            null,
            'No branch is available for voice configuration.',
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }
}
