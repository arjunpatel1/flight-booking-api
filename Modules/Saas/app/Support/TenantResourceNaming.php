<?php

namespace Modules\Saas\Support;

/**
 * The one source of truth for tenant-aware names of shared runtime resources.
 *
 * Every broadcast channel, cache key, Redis key, queue payload key and storage
 * prefix that must be isolated per tenant is built here, so the namespace
 * convention lives in exactly one place. When a name needs to change (e.g. once
 * tenants move to dedicated databases), it changes here and nowhere else.
 *
 * Namespacing is by **tenant id**, matching the existing `makeCacheKey`
 * convention (`tenant.{id}`). Id is collision-free (one id per tenant in the
 * control plane) and query-free on hot paths. The tenant slug is available via
 * TenantContext::slug() where a stable non-sequential token is preferable
 * (logs, external references) and a query is acceptable.
 *
 * PHASE 2 NOTE: this helper is the *standard* for tenant-aware naming. It is
 * adopted by new code and by the runtime hooks (queue/log). It does NOT rewrite
 * existing broadcast channel strings — see the Reverb section of the phase-2
 * report for why that is a coordinated cross-client change, not a backend edit.
 */
class TenantResourceNaming
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    /**
     * Namespace token for the current tenant, or 'shared' when none is bound.
     * 'shared' keeps single-tenant/platform resources partitioned away from any
     * real tenant rather than colliding at the root.
     */
    public function namespace(?int $tenantId = null): string
    {
        $id = $tenantId ?? $this->context->id();

        return $id !== null ? "tenant:{$id}" : 'shared';
    }

    /**
     * Tenant-scoped cache/Redis key. Idempotent: a key already carrying the
     * tenant prefix is returned unchanged, so wrapping is always safe.
     */
    public function cacheKey(string $key, ?int $tenantId = null): string
    {
        $ns = $this->namespace($tenantId);

        return str_starts_with($key, "{$ns}:") ? $key : "{$ns}:{$key}";
    }

    /**
     * Redis key prefix for the current tenant. Trailing colon included so
     * callers concatenate directly: prefix() . 'lock:orders'.
     */
    public function redisPrefix(?int $tenantId = null): string
    {
        return $this->namespace($tenantId) . ':';
    }

    /**
     * Tenant-aware broadcast channel name.
     *
     * The canonical form the platform migrates toward:
     *   tenant.{id}.{channel}
     *
     * Existing channels (`pos.orders.branch.{branchId}`) are NOT rewritten by
     * this phase because clients (Flutter waiter app, Vue) subscribe to the
     * current strings; changing them is a coordinated multi-repo rollout. This
     * method exists so new channels are born correct and the migration has one
     * definition to converge on.
     */
    public function channel(string $channel, ?int $tenantId = null): string
    {
        $id = $tenantId ?? $this->context->id();

        return $id !== null ? "tenant.{$id}.{$channel}" : $channel;
    }

    /**
     * Storage path prefix. Media already uses a tenant directory; this is the
     * canonical form for any new tenant-scoped storage.
     */
    public function storagePrefix(?int $tenantId = null): string
    {
        $id = $tenantId ?? $this->context->id();

        return $id !== null ? "tenants/{$id}" : 'shared';
    }

    /** The job payload key under which tenant context is carried. */
    public const QUEUE_PAYLOAD_KEY = 'tenant_id';
}
