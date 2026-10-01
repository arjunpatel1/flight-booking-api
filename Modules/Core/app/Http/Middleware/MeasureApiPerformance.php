<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MeasureApiPerformance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldMeasure($request)) {
            return $next($request);
        }

        $startedAt = hrtime(true);
        $startedMemory = memory_get_usage(true);
        $requestId = $this->requestId($request);
        $request->headers->set('X-Request-Id', $requestId);
        $request->attributes->set('performance_started_at', $startedAt);
        $request->attributes->set('performance_started_memory', $startedMemory);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $next($request);

        if ((bool) config('performance.api.include_headers', true)) {
            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            [$queryCount, $queryTimeMs] = $this->queryMetrics();
            $response->headers->set('X-Request-Id', $requestId);
            $response->headers->set('X-Query-Count', (string) $queryCount);
            $response->headers->set('Server-Timing', "app;dur={$durationMs}, db;dur={$queryTimeMs}");
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $startedAt = $request->attributes->get('performance_started_at');
        $startedMemory = $request->attributes->get('performance_started_memory');
        $requestId = $request->headers->get('X-Request-Id');

        if (! is_int($startedAt) || ! is_int($startedMemory) || blank($requestId)) {
            return;
        }

        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $memoryDeltaKb = (int) round((memory_get_usage(true) - $startedMemory) / 1024);
        $peakMemoryMb = round(memory_get_peak_usage(true) / 1048576, 2);
        [$queryCount, $queryTimeMs] = $this->queryMetrics();
        $queryFingerprints = (bool) config('performance.api.log_query_fingerprints', false)
            ? $this->queryFingerprints()
            : [];

        $slowThreshold = max((int) config('performance.api.slow_request_ms', 750), 1);
        if (! (bool) config('performance.api.log_slow_requests', true) || $durationMs < $slowThreshold) {
            DB::disableQueryLog();
            DB::flushQueryLog();

            return;
        }

        Log::info('API performance sample.', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'route' => optional($request->route())->getName(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'query_count' => $queryCount,
            'query_time_ms' => $queryTimeMs,
            'memory_delta_kb' => $memoryDeltaKb,
            'peak_memory_mb' => $peakMemoryMb,
            'response_bytes' => $this->responseBytes($response),
            'user_id' => $request->user()?->id,
            'branch_id' => $request->user()?->branch_id,
        ]);

        if (! empty($queryFingerprints)) {
            Log::info('API query fingerprint sample.', [
                'request_id' => $requestId,
                'method' => $request->method(),
                'path' => $request->path(),
                'route' => optional($request->route())->getName(),
                'query_fingerprints' => $queryFingerprints,
            ]);
        }

        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    private function shouldMeasure(Request $request): bool
    {
        if (! (bool) config('performance.api.enabled', false)) {
            return false;
        }

        $sampleRate = max(0.0, min((float) config('performance.api.sample_rate', 1.0), 1.0));
        if ($sampleRate <= 0.0 || ($sampleRate < 1.0 && mt_rand() / mt_getrandmax() > $sampleRate)) {
            return false;
        }

        $paths = config('performance.api.paths', []);
        if (empty($paths)) {
            return $request->is('api/*');
        }

        foreach ($paths as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }

    private function requestId(Request $request): string
    {
        $incoming = trim((string) $request->header('X-Request-Id'));

        return preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $incoming)
            ? $incoming
            : (string) Str::uuid();
    }

    private function responseBytes(Response $response): ?int
    {
        if (! method_exists($response, 'getContent')) {
            return null;
        }

        $content = $response->getContent();

        return is_string($content) ? strlen($content) : null;
    }

    /**
     * @return array{0: int, 1: float}
     */
    private function queryMetrics(): array
    {
        $queries = DB::getQueryLog();

        return [
            count($queries),
            round(array_sum(array_column($queries, 'time')), 2),
        ];
    }

    private function queryFingerprints(): array
    {
        $groups = [];

        foreach (DB::getQueryLog() as $query) {
            $fingerprint = $this->fingerprintSql((string) ($query['query'] ?? ''));

            if (! isset($groups[$fingerprint])) {
                $groups[$fingerprint] = [
                    'fingerprint' => $fingerprint,
                    'count' => 0,
                    'total_ms' => 0.0,
                    'max_ms' => 0.0,
                ];
            }

            $time = (float) ($query['time'] ?? 0);
            $groups[$fingerprint]['count']++;
            $groups[$fingerprint]['total_ms'] += $time;
            $groups[$fingerprint]['max_ms'] = max($groups[$fingerprint]['max_ms'], $time);
        }

        $limit = max((int) config('performance.api.query_fingerprint_limit', 20), 1);
        $fingerprints = array_values($groups);
        usort($fingerprints, static fn(array $left, array $right) => [$right['total_ms'], $right['count']] <=> [$left['total_ms'], $left['count']]);

        return array_map(
            static fn(array $row) => [
                'count' => $row['count'],
                'total_ms' => round($row['total_ms'], 2),
                'max_ms' => round($row['max_ms'], 2),
                'fingerprint' => $row['fingerprint'],
            ],
            array_slice($fingerprints, 0, $limit)
        );
    }

    private function fingerprintSql(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?: '';
        $sql = preg_replace("/'([^'\\\\]|\\\\.)*'/", "'?'", $sql) ?: $sql;
        $sql = preg_replace('/"([^"\\\\]|\\\\.)*"/', '"?"', $sql) ?: $sql;
        $sql = preg_replace('/\b\d+\b/', '?', $sql) ?: $sql;

        return Str::limit($sql, 500, '...');
    }
}
