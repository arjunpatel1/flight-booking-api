<?php

namespace Modules\Core\Services\Monitoring;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Failure Monitor Service
 * 
 * Tracks and monitors failures across the system
 */
class FailureMonitor
{
    private string $cacheKey = 'failure_monitor:';
    private int $retentionDays = 30;

    /**
     * Record a failure event
     */
    public function recordFailure(array $data): void
    {
        try {
            $failure = [
                'id' => (string) Str::uuid(),
                'type' => $data['type'] ?? 'unknown',
                'component' => $data['component'] ?? 'unknown',
                'severity' => $data['severity'] ?? 'error',
                'message' => $data['message'] ?? '',
                'context' => $data['context'] ?? [],
                'occurred_at' => Carbon::now()->toISOString(),
                'metadata' => $data['metadata'] ?? [],
            ];

            // Store in cache for quick access
            $key = $this->cacheKey . $failure['id'];
            Cache::put($key, $failure, now()->addDays($this->retentionDays));

            // Store in database for persistence
            DB::table('failure_events')->insert($failure);

            // Log the failure
            Log::error('Failure recorded', $failure);

            // Trigger alert if critical
            if ($failure['severity'] === 'critical') {
                $this->triggerAlert($failure);
            }
        } catch (\Exception $e) {
            Log::error('Failed to record failure', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get failures by type
     */
    public function getFailuresByType(string $type, int $limit = 100): array
    {
        try {
            return DB::table('failure_events')
                ->where('type', $type)
                ->orderBy('occurred_at', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to get failures by type', ['type' => $type, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get failures by component
     */
    public function getFailuresByComponent(string $component, int $limit = 100): array
    {
        try {
            return DB::table('failure_events')
                ->where('component', $component)
                ->orderBy('occurred_at', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to get failures by component', ['component' => $component, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get failures by severity
     */
    public function getFailuresBySeverity(string $severity, int $limit = 100): array
    {
        try {
            return DB::table('failure_events')
                ->where('severity', $severity)
                ->orderBy('occurred_at', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to get failures by severity', ['severity' => $severity, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get failure statistics
     */
    public function getStatistics(int $hours = 24): array
    {
        try {
            $since = Carbon::now()->subHours($hours);

            $failures = DB::table('failure_events')
                ->where('occurred_at', '>=', $since)
                ->get();

            return [
                'total' => $failures->count(),
                'by_type' => $failures->groupBy('type')->map->count()->toArray(),
                'by_component' => $failures->groupBy('component')->map->count()->toArray(),
                'by_severity' => $failures->groupBy('severity')->map->count()->toArray(),
                'critical_count' => $failures->where('severity', 'critical')->count(),
                'error_count' => $failures->where('severity', 'error')->count(),
                'warning_count' => $failures->where('severity', 'warning')->count(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get failure statistics', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get failure trends
     */
    public function getTrends(int $days = 7): array
    {
        try {
            $trends = [];
            
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = Carbon::now()->subDays($i)->format('Y-m-d');
                $start = Carbon::now()->subDays($i)->startOfDay();
                $end = Carbon::now()->subDays($i)->endOfDay();

                $count = DB::table('failure_events')
                    ->whereBetween('occurred_at', [$start, $end])
                    ->count();

                $trends[] = [
                    'date' => $date,
                    'count' => $count,
                ];
            }

            return $trends;
        } catch (\Exception $e) {
            Log::error('Failed to get failure trends', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get recent failures
     */
    public function getRecentFailures(int $limit = 50): array
    {
        try {
            return DB::table('failure_events')
                ->orderBy('occurred_at', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to get recent failures', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Trigger alert for critical failure
     */
    private function triggerAlert(array $failure): void
    {
        try {
            // Store alert in cache for dashboard
            $alertKey = $this->cacheKey . 'alert:' . $failure['id'];
            Cache::put($alertKey, $failure, now()->addHours(24));

            // Log critical alert
            Log::critical('Critical failure alert triggered', $failure);

            // Here you could integrate with notification systems
            // e.g., email, Slack, PagerDuty, etc.
        } catch (\Exception $e) {
            Log::error('Failed to trigger alert', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get active alerts
     */
    public function getActiveAlerts(): array
    {
        try {
            $alerts = [];
            $keys = Cache::get($this->cacheKey . 'alerts', []);

            foreach ($keys as $key) {
                $alert = Cache::get($key);
                if ($alert) {
                    $alerts[] = $alert;
                }
            }

            return $alerts;
        } catch (\Exception $e) {
            Log::error('Failed to get active alerts', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Clear old failures
     */
    public function clearOldFailures(): void
    {
        try {
            $cutoff = Carbon::now()->subDays($this->retentionDays);
            
            $deleted = DB::table('failure_events')
                ->where('occurred_at', '<', $cutoff)
                ->delete();

            Log::info('Cleared old failures', ['count' => $deleted]);
        } catch (\Exception $e) {
            Log::error('Failed to clear old failures', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get failure by ID
     */
    public function getFailure(string $id): ?array
    {
        try {
            $failure = DB::table('failure_events')
                ->where('id', $id)
                ->first();

            return $failure ? (array) $failure : null;
        } catch (\Exception $e) {
            Log::error('Failed to get failure', ['id' => $id, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
