<?php

namespace Modules\Saas\Services\Infrastructure;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Saas\Models\TenantInfrastructure;
use Throwable;

/**
 * Structured, read-only health of the platform's infrastructure surfaces.
 *
 * Read-only in the strictest sense: every probe is a ping or a config read.
 * Nothing here writes, migrates, restarts or reconfigures. It is safe to call
 * on every dashboard load.
 *
 * In Phase 1 the "database" probe reports the SHARED database (the only one
 * that exists). Per-tenant database health is delegated to
 * TenantConnectionManager and only becomes meaningful once tenants are
 * dedicated — until then it reports "not_configured", truthfully.
 */
class InfrastructureHealthService
{
    public const OK = 'healthy';
    public const WARN = 'degraded';
    public const DOWN = 'down';
    public const UNKNOWN = 'unknown';

    public function __construct(private readonly TenantConnectionManager $connections)
    {
    }

    /**
     * Platform-wide infrastructure snapshot for the read-only dashboard.
     */
    public function platform(): array
    {
        $checks = [
            $this->database(),
            $this->redis(),
            $this->queue(),
            $this->storage(),
            $this->reverb(),
            $this->cache(),
            $this->backup(),
            $this->ssl(),
            $this->disk(),
        ];

        $status = self::OK;
        foreach ($checks as $c) {
            if ($c['status'] === self::DOWN) {
                $status = self::DOWN;
                break;
            }
            if ($c['status'] === self::WARN) {
                $status = self::WARN;
            }
        }

        return [
            'checked_at' => now()->toIso8601String(),
            'status' => $status,
            'checks' => $checks,
            'services' => $this->services($checks),
            'registry' => $this->registrySummary(),
        ];
    }

    /**
     * Per-tenant infrastructure rows for the dashboard table. Descriptive in
     * Phase 1 — reflects the registry, which is empty until tenants are moved.
     */
    public function tenants(): array
    {
        if (! Schema::hasTable('saas_tenant_infrastructure')) {
            return [];
        }

        return TenantInfrastructure::query()
            ->with('tenant:id,name,slug')
            ->get()
            ->map(fn (TenantInfrastructure $row) => [
                'tenant' => $row->tenant?->only(['id', 'name', 'slug']),
                'mode' => $row->mode,
                'db_driver' => $row->db_driver,
                'db_host' => $row->db_host,
                'db_name' => $row->db_name,
                'connection_status' => $row->connection_status,
                'migration_version' => $row->migration_version,
                'health_status' => $row->health_status,
                'latency_ms' => $row->latency_ms,
                'backup_status' => $row->backup_status,
                'last_backup_at' => optional($row->last_backup_at)->toIso8601String(),
                'last_health_check_at' => optional($row->last_health_check_at)->toIso8601String(),
            ])
            ->all();
    }

    // --- individual probes ---------------------------------------------------

    private function database(): array
    {
        $startedAt = microtime(true);
        try {
            DB::connection()->getPdo();
            DB::select('select 1');
            $latency = (int) round((microtime(true) - $startedAt) * 1000);

            return $this->probe('database', self::OK,
                'Shared database "' . DB::connection()->getDatabaseName() . '" reachable.',
                ['driver' => config('database.default'), 'latency_ms' => $latency]);
        } catch (Throwable $e) {
            return $this->probe('database', self::DOWN, 'Database unreachable: ' . $e->getMessage());
        }
    }

    private function redis(): array
    {
        try {
            $startedAt = microtime(true);
            Redis::connection()->ping();
            $latency = (int) round((microtime(true) - $startedAt) * 1000);
            $info = $this->redisInfo();

            return $this->probe('redis', self::OK, 'Redis responding.', [
                'latency_ms' => $latency,
                'memory_human' => $info['used_memory_human'] ?? null,
                'connected_clients' => $info['connected_clients'] ?? null,
                'hit_rate' => $this->redisHitRate($info),
                'version' => $info['redis_version'] ?? null,
            ]);
        } catch (Throwable $e) {
            // Redis being absent in a dev/test environment is degraded, not down —
            // the app runs without it.
            return $this->probe('redis', self::WARN, 'Redis not reachable: ' . $e->getMessage());
        }
    }

    private function queue(): array
    {
        $driver = config('queue.default');
        $hasFailedJobs = Schema::hasTable('failed_jobs');
        $failed = $hasFailedJobs ? DB::table('failed_jobs')->count() : null;
        $recentFailed = $hasFailedJobs && Schema::hasColumn('failed_jobs', 'failed_at')
            ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count()
            : $failed;
        $pending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : null;

        $status = $driver === 'sync' ? self::WARN : self::OK;
        if (($recentFailed ?? 0) > 0) {
            $status = self::WARN;
        }

        $oldest = Schema::hasTable('jobs') && Schema::hasColumn('jobs', 'created_at')
            ? DB::table('jobs')->min('created_at')
            : null;
        $oldestAge = $oldest ? $this->ageSeconds($oldest) : 0;

        return $this->probe('queue', $status,
            'Queue driver "' . $driver . '".',
            [
                'driver' => $driver,
                'pending' => $pending,
                'failed' => $recentFailed,
                'failed_last_24h' => $recentFailed,
                'failed_historical' => $failed,
                'oldest_wait_seconds' => $oldestAge,
            ]);
    }

    private function storage(): array
    {
        try {
            $disk = config('filesystems.default');
            $writable = is_writable(storage_path('app'));
            $linked = file_exists(public_path('storage'));

            $status = $writable ? ($linked ? self::OK : self::WARN) : self::DOWN;

            return $this->probe('storage', $status,
                $writable ? ($linked ? 'Storage writable, public link present.' : 'public/storage symlink missing.')
                    : 'Storage path not writable.',
                ['disk' => $disk]);
        } catch (Throwable $e) {
            return $this->probe('storage', self::DOWN, $e->getMessage());
        }
    }

    private function reverb(): array
    {
        $configured = filled(config('broadcasting.connections.reverb.key'));

        return $this->probe('reverb', $configured ? self::OK : self::WARN,
            $configured ? 'Reverb credentials configured.' : 'Reverb app key not set.',
            ['host' => config('broadcasting.connections.reverb.options.host')]);
    }

    private function cache(): array
    {
        $driver = config('cache.default');
        $ok = in_array($driver, ['redis', 'memcached'], true);

        return $this->probe('cache', $ok ? self::OK : self::WARN,
            'Cache store "' . $driver . '".', ['driver' => $driver]);
    }

    private function backup(): array
    {
        // Whole-instance backups exist today; per-tenant backup arrives later.
        $hasTable = Schema::hasTable('system_backups');
        $last = $hasTable ? DB::table('system_backups')->max('created_at') : null;

        return $this->probe('backup', $hasTable ? self::OK : self::UNKNOWN,
            $hasTable ? 'Instance backup subsystem present.' : 'Backup subsystem not detected.',
            ['last_backup_at' => $last, 'scope' => 'instance']);
    }

    private function ssl(): array
    {
        // Cannot be proven from inside PHP; report as informational, never green
        // on a claim we did not verify.
        return $this->probe('ssl', self::UNKNOWN,
            'TLS termination must be verified at the web server; not observable in-process.');
    }

    private function disk(): array
    {
        try {
            $path = storage_path();
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);
            if (! $free || ! $total) {
                return $this->probe('disk', self::UNKNOWN, 'Disk usage unavailable.');
            }
            $usedPct = (int) round(($total - $free) / $total * 100);
            $status = $usedPct >= 90 ? self::WARN : self::OK;

            return $this->probe('disk', $status, "Disk {$usedPct}% used.", [
                'used_percent' => $usedPct,
                'free_gb' => round($free / 1_073_741_824, 1),
            ]);
        } catch (Throwable $e) {
            return $this->probe('disk', self::UNKNOWN, $e->getMessage());
        }
    }

    private function services(array $checks): array
    {
        $byKey = collect($checks)->keyBy('key');
        $redis = $byKey->get('redis', []);
        $queue = $byKey->get('queue', []);
        $storage = $byKey->get('storage', []);
        $reverb = $byKey->get('reverb', []);
        $disk = $byKey->get('disk', []);
        $horizon = $this->horizonService();
        $supervisor = $this->supervisorService();
        $octane = $this->octaneService();
        $scheduler = $this->schedulerService();

        return [
            'redis' => $this->service('Redis', $redis['status'] ?? self::UNKNOWN, $redis['message'] ?? 'Redis status unavailable.', [
                'latency_ms' => data_get($redis, 'meta.latency_ms'),
                'memory' => data_get($redis, 'meta.memory_human'),
                'connections' => data_get($redis, 'meta.connected_clients'),
                'hit_rate' => data_get($redis, 'meta.hit_rate'),
                'version' => data_get($redis, 'meta.version'),
            ]),
            'horizon' => $horizon,
            'supervisor' => $supervisor,
            'queue' => $this->service('Queue', $queue['status'] ?? self::UNKNOWN, $queue['message'] ?? 'Queue status unavailable.', [
                'pending' => data_get($queue, 'meta.pending'),
                'failed' => data_get($queue, 'meta.failed'),
                'oldest_wait_seconds' => data_get($queue, 'meta.oldest_wait_seconds'),
            ]),
            'reverb' => $this->service('Reverb', $reverb['status'] ?? self::UNKNOWN, $reverb['message'] ?? 'Reverb status unavailable.', [
                'host' => data_get($reverb, 'meta.host'),
                'latency_ms' => null,
            ]),
            'octane' => $octane,
            'scheduler' => $scheduler,
            'storage' => $this->service('Storage', $storage['status'] ?? self::UNKNOWN, $storage['message'] ?? 'Storage status unavailable.', [
                'disk' => data_get($storage, 'meta.disk'),
                'disk_used_percent' => data_get($disk, 'meta.used_percent'),
                'disk_free_gb' => data_get($disk, 'meta.free_gb'),
            ]),
            'system' => $this->service('System', self::OK, 'PHP process and host resource snapshot.', [
                'cpu_load_1m' => $this->loadAverage()[0],
                'cpu_load_5m' => $this->loadAverage()[1],
                'ram_php_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'ram_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ]),
        ];
    }

    private function horizonService(): array
    {
        return $this->safe(function () {
            $redis = Redis::connection();
            $masters = collect($redis->smembers('horizon:masters') ?: []);
            $supervisors = $masters
                ->flatMap(fn ($master) => $redis->smembers("horizon:supervisors:{$master}") ?: [])
                ->values();
            $workloads = collect($redis->hgetall('horizon:workloads') ?: [])
                ->map(fn ($payload) => json_decode((string) $payload, true) ?: []);
            $workers = (int) $workloads->sum(fn ($payload) => (int) data_get($payload, 'processes', 0));
            $busy = (int) $workloads->sum(fn ($payload) => (int) data_get($payload, 'busyProcesses', 0));
            $utilization = $workers > 0 ? round(($busy / $workers) * 100, 2) : 0.0;
            $status = $masters->isNotEmpty() || $supervisors->isNotEmpty() ? self::OK : self::WARN;

            return $this->service('Horizon', $status, $status === self::OK ? 'Horizon telemetry is available.' : 'Horizon Redis telemetry not detected.', [
                'masters' => $masters->count(),
                'supervisors' => $supervisors->count(),
                'workers' => $workers,
                'workers_busy' => $busy,
                'workers_idle' => max(0, $workers - $busy),
                'worker_utilization' => $utilization,
            ]);
        }, $this->service('Horizon', self::WARN, 'Horizon telemetry unavailable.', ['workers' => 0, 'worker_utilization' => 0]));
    }

    private function supervisorService(): array
    {
        $path = (string) data_get(config('saas.server_automation'), 'processes.supervisorctl', '/usr/bin/supervisorctl');
        $available = is_file($path) && is_executable($path);

        return $this->service('Supervisor', $available ? self::OK : self::WARN, $available ? 'supervisorctl is available.' : 'supervisorctl not found or not executable.', [
            'path' => $path,
            'available' => $available,
            'worker_program' => data_get(config('saas.server_automation'), 'processes.worker_program'),
            'reverb_program' => data_get(config('saas.server_automation'), 'processes.reverb_program'),
        ]);
    }

    private function octaneService(): array
    {
        $configured = class_exists(\Laravel\Octane\Octane::class);
        $server = (string) config('octane.server', 'unknown');
        $warm = count((array) config('octane.warm', []));
        $flush = count((array) config('octane.flush', []));

        return $this->service('Octane', $configured ? self::OK : self::UNKNOWN, $configured ? "Octane configured for {$server}." : 'Octane package not detected in runtime.', [
            'server' => $server,
            'warm_services' => $warm,
            'flush_bindings' => $flush,
            'max_execution_time' => config('octane.max_execution_time'),
            'restart_required' => false,
        ]);
    }

    private function schedulerService(): array
    {
        $heartbeat = $this->schedulerHeartbeat();
        $healthy = filled($heartbeat) && Carbon::parse($heartbeat)->greaterThan(now()->subMinutes(3));

        return $this->service('Scheduler', $healthy ? self::OK : self::WARN, $heartbeat ? 'Last heartbeat '.Carbon::parse($heartbeat)->diffForHumans() : 'No scheduler heartbeat recorded.', [
            'last_checked' => $heartbeat,
            'restart_required' => ! $healthy,
        ]);
    }

    private function registrySummary(): array
    {
        if (! Schema::hasTable('saas_tenant_infrastructure')) {
            return ['available' => false, 'shared' => null, 'dedicated' => null];
        }

        return [
            'available' => true,
            'total' => TenantInfrastructure::query()->count(),
            'shared' => TenantInfrastructure::query()->where('mode', TenantInfrastructure::MODE_SHARED)->count(),
            'dedicated' => TenantInfrastructure::query()->whereIn('mode', ['dedicated', 'cluster'])->count(),
        ];
    }

    private function probe(string $key, string $status, string $message, array $meta = []): array
    {
        return ['key' => $key, 'status' => $status, 'message' => $message, 'meta' => $meta, 'last_checked_at' => now()->toIso8601String()];
    }

    private function service(string $label, string $status, string $message, array $metrics = []): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'message' => $message,
            'last_checked_at' => now()->toIso8601String(),
            'latency_ms' => $metrics['latency_ms'] ?? null,
            'version' => $metrics['version'] ?? null,
            'restart_required' => (bool) ($metrics['restart_required'] ?? false),
            'configuration_problems' => $status === self::OK ? [] : [$message],
            'metrics' => $metrics,
        ];
    }

    private function redisInfo(): array
    {
        return $this->safe(function () {
            $raw = Redis::connection()->command('info');
            if (is_array($raw)) {
                return $raw;
            }

            $info = [];
            foreach (preg_split('/\r?\n/', (string) $raw) as $line) {
                if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, ':')) {
                    continue;
                }
                [$key, $value] = explode(':', $line, 2);
                $info[$key] = trim($value);
            }

            return $info;
        }, []);
    }

    private function redisHitRate(array $info): ?float
    {
        $hits = (int) ($info['keyspace_hits'] ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);
        $total = $hits + $misses;

        return $total > 0 ? round(($hits / $total) * 100, 2) : null;
    }

    private function schedulerHeartbeat(): ?string
    {
        $cached = $this->safe(fn () => Cache::get('saas:scheduler-heartbeat'), null);
        if (filled($cached)) return (string) $cached;
        if (! Storage::disk('local')->exists('saas/scheduler-heartbeat')) return null;

        return trim((string) Storage::disk('local')->get('saas/scheduler-heartbeat')) ?: null;
    }

    private function loadAverage(): array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];

        return [round((float) ($load[0] ?? 0), 2), round((float) ($load[1] ?? 0), 2)];
    }

    private function ageSeconds(mixed $date): int
    {
        if (! filled($date)) return 0;
        $carbon = is_numeric($date) ? Carbon::createFromTimestamp((int) $date) : Carbon::parse($date);

        return max(0, $carbon->diffInSeconds(now()));
    }

    private function safe(callable $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return $fallback;
        }
    }
}
