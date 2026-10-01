<?php

namespace Modules\Saas\Services\Infrastructure;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Saas\Models\TenantInfrastructure;
use Throwable;

/**
 * Resolves and prepares a tenant's dedicated database connection.
 *
 * PHASE 1 CONTRACT — READ THIS BEFORE ASSUMING BEHAVIOUR:
 *
 *   This service is NOT wired into any request, middleware, job or query.
 *   Nothing in the running application calls it. It exists so the connection
 *   machinery can be built and unit-reasoned in isolation, ahead of the phase
 *   that actually binds tenant connections.
 *
 *   Critically, it NEVER changes the default connection and NEVER runs a query
 *   as a side effect of resolution. `prepare()` registers a *named* connection
 *   config (`tenant_{id}`) so it could be used explicitly; it does not touch
 *   `config('database.default')`. The shared database remains the only
 *   connection any existing code sees.
 *
 * When Phase 2 wires this in, the entry point will be `prepare()` + an explicit
 * `DB::connection($name)` or a purpose-built middleware — not a global default
 * swap hidden inside this class.
 */
class TenantConnectionManager
{
    /** Per-request cache of resolved connection names, keyed by tenant id. */
    private array $resolved = [];

    /**
     * The registry row for a tenant, or null if it has none (→ shared DB).
     * Guarded so it is safe to call before the registry migration has run.
     */
    public function registryFor(int $tenantId): ?TenantInfrastructure
    {
        if (! Schema::hasTable('saas_tenant_infrastructure')) {
            return null;
        }

        return TenantInfrastructure::query()->where('tenant_id', $tenantId)->first();
    }

    /**
     * The Laravel connection name a tenant should use.
     *
     * In Phase 1 this returns the DEFAULT connection for every tenant, because
     * none is dedicated yet — the shared database. It only ever returns a
     * dedicated name once a tenant has real connection config, which will not
     * happen until a later phase.
     */
    public function connectionNameFor(int $tenantId): string
    {
        $default = (string) config('database.default');

        $registry = $this->registryFor($tenantId);
        if (! $registry || ! $registry->hasConnectionConfig()) {
            return $default;
        }

        return $this->prepare($registry);
    }

    /**
     * Register (but do not activate) a named connection built from a registry
     * row. Returns the connection name. Idempotent and cached per request.
     *
     * Does NOT open the socket and does NOT change the default connection.
     */
    public function prepare(TenantInfrastructure $registry): string
    {
        $tenantId = $registry->tenant_id;

        if (isset($this->resolved[$tenantId])) {
            return $this->resolved[$tenantId];
        }

        $name = "tenant_{$tenantId}";

        // Build the connection config off the platform's existing default so
        // driver-level options (charset, options, strict mode) are inherited,
        // then override only the per-tenant coordinates.
        $base = config('database.connections.' . config('database.default'), []);

        Config::set("database.connections.{$name}", array_merge($base, [
            'driver' => $registry->db_driver ?: ($base['driver'] ?? 'mysql'),
            'host' => $registry->db_host,
            'port' => $registry->db_port ?: ($base['port'] ?? null),
            'database' => $registry->db_name,
            'username' => $registry->db_username,
            'password' => $registry->db_password, // decrypted by the model cast
        ]));

        return $this->resolved[$tenantId] = $name;
    }

    /**
     * Actively test a tenant connection. Opens, pings, measures latency, closes.
     * Returns a structured result; never throws.
     *
     * Safe to call in Phase 1 — for a shared/unconfigured tenant it reports
     * "not_configured" without touching anything.
     */
    public function healthCheck(int $tenantId): array
    {
        $registry = $this->registryFor($tenantId);

        if (! $registry || ! $registry->hasConnectionConfig()) {
            return [
                'status' => 'not_configured',
                'reachable' => false,
                'latency_ms' => null,
                'message' => 'Tenant is on the shared database; no dedicated connection to check.',
            ];
        }

        $name = $this->prepare($registry);
        $startedAt = microtime(true);

        try {
            DB::connection($name)->getPdo();
            DB::connection($name)->select('select 1');
            $latency = (int) round((microtime(true) - $startedAt) * 1000);

            return [
                'status' => 'healthy',
                'reachable' => true,
                'latency_ms' => $latency,
                'message' => 'Connection established.',
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'down',
                'reachable' => false,
                'latency_ms' => null,
                'message' => $e->getMessage(),
            ];
        } finally {
            // Never leave a tenant socket open on a shared worker.
            try {
                DB::purge($name);
            } catch (Throwable) {
            }
        }
    }

    /**
     * Forget all cached resolutions. Called by whatever owns request/job
     * teardown once this is wired in; harmless to call now.
     */
    public function flush(): void
    {
        $this->resolved = [];
    }
}
