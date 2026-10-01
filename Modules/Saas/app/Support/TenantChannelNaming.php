<?php

namespace Modules\Saas\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maps a v1 (bare-id) realtime channel to its v2 (tenant-namespaced) sibling.
 *
 *   v1: pos.orders.branch.6              v2: pos.tenant.{tid}.branch.6
 *   v1: pos.kitchen.branch.6            v2: pos.tenant.{tid}.branch.6  (kept as its own kind)
 *   v1: branch.6                         v2: tenant.{tid}.branch.6
 *   v1: agent.abc                        v2: tenant.{tid}.agent.abc
 *   v1: notifications.user.5             v2: notifications.tenant.{tid}.user.5
 *
 * Why tenant id and not a branch uuid: the collision problem is that a bare
 * branch id repeats across tenants once databases are dedicated. Prefixing with
 * the tenant id — which IS globally unique in the control plane — makes the
 * branch id unique *within its tenant namespace*. No branch uuid is required
 * (and adding one would be a schema change to a business table). The tenant slug
 * could be substituted for the id here and on the clients if a non-sequential
 * token is later wanted; the collision-freedom comes from the prefix, not from
 * the token being a uuid.
 *
 * Resolution (branch/agent/user → tenant) is cached because the mapping is
 * effectively immutable. If a tenant cannot be resolved, the caller keeps the
 * v1 channel only — realtime must never break because a lookup failed.
 */
class TenantChannelNaming
{
    /** How the v2 token is derived. Kept in one place so clients can mirror it. */
    public const VERSION_V1 = 'v1';
    public const VERSION_V2 = 'v2';

    private const CACHE_TTL = 86400; // 24h; mapping is stable

    /**
     * Return the v2 sibling of a v1 channel name, or null if this channel is
     * not one we namespace or the tenant cannot be resolved.
     *
     * Operates on the raw channel string, preserving any `private-`/`presence-`
     * prefix the broadcaster passes through.
     */
    public function toV2(string $channel): ?string
    {
        [$prefix, $name] = $this->splitPrefix($channel);

        // pos.{kind}.branch.{id}
        if (preg_match('/^(pos\.[a-z_]+)\.branch\.(\d+)$/', $name, $m)) {
            $tid = $this->tenantForBranch((int) $m[2]);
            return $tid ? "{$prefix}{$m[1]}.tenant.{$tid}.branch.{$m[2]}" : null;
        }

        // branch.{id}  (voice / generic branch channel)
        if (preg_match('/^branch\.(\d+)$/', $name, $m)) {
            $tid = $this->tenantForBranch((int) $m[1]);
            return $tid ? "{$prefix}tenant.{$tid}.branch.{$m[1]}" : null;
        }

        // agent.{agentId}
        if (preg_match('/^agent\.(.+)$/', $name, $m)) {
            $tid = $this->tenantForAgent($m[1]);
            return $tid ? "{$prefix}tenant.{$tid}.agent.{$m[1]}" : null;
        }

        // notifications.user.{id}
        if (preg_match('/^notifications\.user\.(\d+)$/', $name, $m)) {
            $tid = $this->tenantForUser((int) $m[1]);
            return $tid ? "{$prefix}notifications.tenant.{$tid}.user.{$m[1]}" : null;
        }

        return null;
    }

    /** Whether a channel name is already in v2 (tenant-namespaced) form. */
    public function isV2(string $channel): bool
    {
        [, $name] = $this->splitPrefix($channel);

        return str_contains($name, '.tenant.') || str_starts_with($name, 'tenant.');
    }

    public function tenantForBranch(int $branchId): ?int
    {
        return Cache::remember("chanmap:branch:{$branchId}", self::CACHE_TTL, function () use ($branchId) {
            if (! Schema::hasTable('branches')) {
                return null;
            }
            $tid = DB::table('branches')->where('id', $branchId)->value('tenant_id');
            return is_numeric($tid) ? (int) $tid : null;
        });
    }

    public function tenantForUser(int $userId): ?int
    {
        return Cache::remember("chanmap:user:{$userId}", self::CACHE_TTL, function () use ($userId) {
            if (! Schema::hasTable('users')) {
                return null;
            }
            $tid = DB::table('users')->where('id', $userId)->value('tenant_id');
            return is_numeric($tid) ? (int) $tid : null;
        });
    }

    public function tenantForAgent(string $agentId): ?int
    {
        return Cache::remember("chanmap:agent:{$agentId}", self::CACHE_TTL, function () use ($agentId) {
            if (! Schema::hasTable('print_agents') || ! Schema::hasTable('branches')) {
                return null;
            }
            $branchId = DB::table('print_agents')->where('agent_id', $agentId)->value('branch_id');
            if (! is_numeric($branchId)) {
                return null;
            }
            $tid = DB::table('branches')->where('id', (int) $branchId)->value('tenant_id');
            return is_numeric($tid) ? (int) $tid : null;
        });
    }

    /**
     * @return array{0:string,1:string} [prefix, bareName]
     */
    private function splitPrefix(string $channel): array
    {
        foreach (['private-', 'presence-'] as $p) {
            if (str_starts_with($channel, $p)) {
                return [$p, substr($channel, strlen($p))];
            }
        }

        return ['', $channel];
    }
}
