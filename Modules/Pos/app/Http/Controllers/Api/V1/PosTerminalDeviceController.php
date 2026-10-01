<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Modules\Pos\Models\PosOfflineOrder;
use Modules\Pos\Models\PosTerminalDevice;

class PosTerminalDeviceController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->userTenantId($request);
        $validated = $request->validate([
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'pos_register_id' => [
                'nullable',
                Rule::exists('pos_registers', 'id')->where(
                    fn ($registers) => $registers->whereIn(
                        'branch_id',
                        fn ($branches) => $branches->select('id')->from('branches')->where('tenant_id', $tenantId)
                    )
                ),
            ],
            'status' => 'nullable|in:online,offline,syncing,error',
            'offline_after_seconds' => 'nullable|integer|min:30|max:3600',
            'per_page' => 'nullable|integer|min:10|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        if (! Schema::hasTable('pos_terminal_devices')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'storage_ready' => false,
                    'total' => 0,
                    'online' => 0,
                    'offline' => 0,
                    'syncing' => 0,
                    'error' => 0,
                    'queued_requests' => 0,
                    'current_page' => 1,
                    'last_page' => 1,
                    'filtered_total' => 0,
                ],
            ]);
        }

        $offlineAfterSeconds = (int) ($validated['offline_after_seconds'] ?? 90);
        $cutoff = now()->subSeconds($offlineAfterSeconds);
        $baseQuery = PosTerminalDevice::query()
            ->when(! empty($validated['branch_id']), fn($query) => $query->where('branch_id', $validated['branch_id']))
            ->when(! empty($validated['pos_register_id']), fn($query) => $query->where('pos_register_id', $validated['pos_register_id']));

        // Cache metrics for 30 seconds to reduce database load
        $metricsCacheKey = makeCacheKey([
            'terminal-metrics',
            'tenant-' . ($tenantId ?? 'none'),
            'branch-' . ($validated['branch_id'] ?? 'all'),
            'register-' . ($validated['pos_register_id'] ?? 'all'),
            'cutoff-' . $cutoff->timestamp,
        ]);

        $metrics = \Illuminate\Support\Facades\Cache::remember(
            $metricsCacheKey,
            now()->addSeconds(30),
            function () use ($baseQuery, $cutoff) {
                return (clone $baseQuery)
                    ->selectRaw('COUNT(*) AS total')
                    ->selectRaw('SUM(CASE WHEN status = ? AND last_seen_at >= ? THEN 1 ELSE 0 END) AS online', ['online', $cutoff])
                    ->selectRaw('SUM(CASE WHEN status = ? AND last_seen_at >= ? THEN 1 ELSE 0 END) AS syncing', ['syncing', $cutoff])
                    ->selectRaw('SUM(CASE WHEN status = ? AND last_seen_at >= ? THEN 1 ELSE 0 END) AS error', ['error', $cutoff])
                    ->selectRaw('SUM(CASE WHEN status = ? OR last_seen_at IS NULL OR last_seen_at < ? THEN 1 ELSE 0 END) AS offline', ['offline', $cutoff])
                    ->selectRaw('COALESCE(SUM(local_queue_count + server_queue_count), 0) AS queued_requests')
                    ->first();
            }
        );

        $devicesQuery = (clone $baseQuery)
            ->with(['branch:id,name', 'posRegister:id,name'])
            ->when(($validated['status'] ?? null) === 'offline', fn($query) => $query->where(
                fn($rows) => $rows
                    ->where('status', 'offline')
                    ->orWhereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', $cutoff)
            ))
            ->when(in_array($validated['status'] ?? null, ['online', 'syncing', 'error'], true), fn($query) => $query
                ->where('status', $validated['status'])
                ->where('last_seen_at', '>=', $cutoff));
        $devices = $devicesQuery
            ->latest('last_seen_at')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $minAppVersion = config('pos.fleet.min_app_version');

        return response()->json([
            'data' => $devices->getCollection()
                ->map(fn(PosTerminalDevice $device) => $device->toStatusPayload($offlineAfterSeconds, $minAppVersion))
                ->values(),
            'meta' => [
                'storage_ready' => true,
                'total' => (int) ($metrics->total ?? 0),
                'online' => (int) ($metrics->online ?? 0),
                'offline' => (int) ($metrics->offline ?? 0),
                'syncing' => (int) ($metrics->syncing ?? 0),
                'error' => (int) ($metrics->error ?? 0),
                'queued_requests' => (int) ($metrics->queued_requests ?? 0),
                'current_page' => $devices->currentPage(),
                'last_page' => $devices->lastPage(),
                'filtered_total' => $devices->total(),
            ],
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $tenantId = $this->userTenantId($request);
        $userBranchId = $this->userBranchId($request);

        $validated = $request->validate([
            'device_id' => 'nullable|string|max:120',
            'name' => 'nullable|string|max:120',
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'pos_register_id' => [
                'nullable',
                Rule::exists('pos_registers', 'id')->where(
                    fn ($registers) => $registers->whereIn(
                        'branch_id',
                        fn ($branches) => $branches->select('id')->from('branches')->where('tenant_id', $tenantId)
                    )
                ),
            ],
            'pos_session_id' => [
                'nullable',
                Rule::exists('pos_sessions', 'id')->where(
                    fn ($sessions) => $sessions->whereIn(
                        'branch_id',
                        fn ($branches) => $branches->select('id')->from('branches')->where('tenant_id', $tenantId)
                    )
                ),
            ],
            'status' => 'nullable|in:online,offline,syncing,error',
            'app_version' => 'nullable|string|max:80',
            'platform' => 'nullable|string|max:120',
            'browser' => 'nullable|string|max:120',
            'push_token' => 'nullable|string|max:4096',
            'local_queue_count' => 'nullable|integer|min:0|max:100000',
            'last_sync_at' => 'nullable|date',
            'meta' => 'nullable|array',
            'meta.app_started_at' => 'nullable|date',
            'meta.app_uptime_seconds' => 'nullable|integer|min:0|max:31536000',
            'meta.build_mode' => 'nullable|string|in:release,profile,debug',
            'meta.heartbeat_interval_seconds' => 'nullable|integer|min:5|max:3600',
            'meta.screen' => 'nullable|string|max:40',
            // Advanced fleet metrics (fleet dashboard health columns).
            'meta.battery_level' => 'nullable|integer|min:0|max:100',
            'meta.battery_charging' => 'nullable|boolean',
            'meta.os_version' => 'nullable|string|max:80',
            'meta.device_model' => 'nullable|string|max:120',
            'meta.crash_count' => 'nullable|integer|min:0|max:1000000',
            // Runtime identity (Phase 2.6). Optional and stored in the existing
            // meta json — no schema change, no persistence-logic change. Lets the
            // fleet dashboard see which runtime/channel version each device runs,
            // which is how v1-retirement readiness is judged per device.
            'meta.tenant_uuid' => 'nullable|string|max:120',
            'meta.branch_uuid' => 'nullable|string|max:120',
            'meta.runtime_version' => 'nullable|string|max:40',
            'meta.channel_version' => 'nullable|string|in:v1,v2',
        ]);

        $deviceId = $validated['device_id']
            ?? (mb_substr((string) $request->header('X-NexDine-Device-Id'), 0, 120) ?: null);

        abort_if(empty($deviceId), 422, __('validation.required', ['attribute' => 'device_id']));

        $status = $validated['status'] ?? ($request->boolean('offline') ? 'offline' : 'online');
        abort_if(
            $request->user()->assignedToBranch()
                && ! empty($validated['branch_id'])
                && (int) $validated['branch_id'] !== $userBranchId,
            403,
            'Branch is not available for this terminal user.'
        );

        if (! Schema::hasTable('pos_terminal_devices')) {
            return response()->json([
                'data' => [
                    'device_id' => $deviceId,
                    'status' => $status,
                    'storage_ready' => false,
                ],
            ], 202);
        }

        $existingDevice = PosTerminalDevice::withoutGlobalScopes()
            ->withTrashed()
            ->where('device_id', $deviceId)
            ->first();

        abort_if(
            $existingDevice && ! $this->deviceBelongsToTenant($existingDevice, $tenantId),
            409,
            'This device identifier is already registered.'
        );

        $assignmentPending = (bool) data_get($existingDevice?->meta, 'assignment.pending_claim', false);
        if ($existingDevice && $assignmentPending && ! $this->canClaimAssignedTerminal($request, $existingDevice)) {
            return response()->json([
                'message' => 'This terminal is not available for the signed-in restaurant.',
                'assignment_required' => true,
                'directives' => [
                    'force_logout' => true,
                    'disabled_reason' => 'Terminal assignment does not match this restaurant.',
                ],
            ], 403);
        }

        abort_if(
            ! $assignmentPending
                && $existingDevice?->created_by
                && (int) $existingDevice->created_by !== (int) auth()->id(),
            403,
            'This terminal is registered to another user.'
        );

        $meta = array_replace(
            $existingDevice?->meta ?? [],
            $this->terminalMeta($validated['meta'] ?? []) ?? []
        );
        if ($assignmentPending) {
            unset($meta['assignment']);
        }

        $branchId = $existingDevice?->branch_id
            ?? ($validated['branch_id'] ?? $userBranchId);
        if (! $branchId) {
            // Global tenant pages can emit connectivity events before the POS
            // has selected a branch. A heartbeat without operational context
            // is valid but must not create an unassigned fleet record.
            return response()->json([
                'data' => [
                    'device_id' => $deviceId,
                    'status' => $status,
                    'storage_ready' => false,
                    'assignment_required' => true,
                ],
                'directives' => [],
            ], 202);
        }
        $this->assertPosResourcesBelongToBranch($validated, (int) $branchId);
        $serverQueueCount = $this->serverQueueCount($deviceId, (int) $branchId);
        $attrs = [
            'created_by' => $existingDevice?->created_by ?: auth()->id(),
            'name' => $validated['name'] ?? $existingDevice?->name,
            // Once registered, branch ownership is controlled by SaaS Admin.
            // A normal heartbeat must never move a terminal between tenants.
            'branch_id' => $branchId,
            'pos_register_id' => array_key_exists('pos_register_id', $validated)
                ? $validated['pos_register_id']
                : $existingDevice?->pos_register_id,
            'pos_session_id' => array_key_exists('pos_session_id', $validated)
                ? $validated['pos_session_id']
                : $existingDevice?->pos_session_id,
            'status' => $status,
            'app_version' => $validated['app_version'] ?? $existingDevice?->app_version,
            'platform' => $validated['platform'] ?? $existingDevice?->platform,
            'browser' => $validated['browser'] ?? $existingDevice?->browser,
            'push_token' => $validated['push_token'] ?? $existingDevice?->push_token,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'local_queue_count' => $validated['local_queue_count'] ?? 0,
            'server_queue_count' => $serverQueueCount,
            'last_seen_at' => now(),
            'last_offline_at' => $status === 'offline' ? now() : null,
            'last_sync_at' => $validated['last_sync_at'] ?? $existingDevice?->last_sync_at,
            'meta' => empty($meta) ? null : $meta,
        ];
        try {
            $device = PosTerminalDevice::withoutGlobalScopes()
                ->withTrashed()
                ->updateOrCreate(
                ['device_id' => $deviceId],
                $attrs
            );
            if ($device->trashed()) {
                $device->restore();
            }
        } catch (UniqueConstraintViolationException) {
            // Race condition: two concurrent requests raced on the same device_id.
            // The row now exists — just update it.
            $device = PosTerminalDevice::withoutGlobalScopes()
                ->withTrashed()
                ->where('device_id', $deviceId)
                ->first();
            $device?->update($attrs);
            if ($device?->trashed()) {
                $device->restore();
            }
        }

        abort_if(! $device, 503, 'Unable to persist terminal heartbeat.');
        $device->refresh();

        $minAppVersion = config('pos.fleet.min_app_version');

        return response()->json([
            'data' => $device->toStatusPayload(
                (int) config('pos.fleet.offline_after_seconds', 90),
                $minAppVersion
            ),
            // Control plane: the terminal must act on these immediately.
            'directives' => $device->controlDirectives($minAppVersion),
        ]);
    }

    /**
     * Remotely disable a terminal (e.g. lost/compromised/decommissioned).
     * The device is force-logged-out on its next heartbeat. Audited.
     */
    public function disable(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $device = PosTerminalDevice::query()->findOrFail($id);
        $device->update([
            'is_disabled' => true,
            'disabled_at' => now(),
            'disabled_reason' => $validated['reason'] ?? null,
            'disabled_by' => auth()->id(),
        ]);

        return response()->json([
            'data' => $device->fresh()->toStatusPayload(
                (int) config('pos.fleet.offline_after_seconds', 90),
                config('pos.fleet.min_app_version')
            ),
        ]);
    }

    /**
     * Re-enable a previously disabled terminal so it can authenticate again.
     */
    public function enable(int $id): JsonResponse
    {
        $device = PosTerminalDevice::query()->findOrFail($id);
        $device->update([
            'is_disabled' => false,
            'disabled_at' => null,
            'disabled_reason' => null,
            'disabled_by' => null,
        ]);

        return response()->json([
            'data' => $device->fresh()->toStatusPayload(
                (int) config('pos.fleet.offline_after_seconds', 90),
                config('pos.fleet.min_app_version')
            ),
        ]);
    }

    private function serverQueueCount(string $deviceId, int $branchId): int
    {
        if (! Schema::hasTable('pos_offline_orders')) {
            return 0;
        }

        return PosOfflineOrder::query()
            ->where('device_id', $deviceId)
            ->where('branch_id', $branchId)
            ->whereNull('synced_at')
            ->whereIn('sync_status', ['pending', 'retrying', 'processing', 'failed'])
            ->count();
    }

    private function deviceBelongsToTenant(PosTerminalDevice $device, ?int $tenantId): bool
    {
        if (! $tenantId || ! $device->branch_id) {
            return false;
        }

        return \Modules\Branch\Models\Branch::query()
            ->withoutGlobalScopes()
            ->whereKey($device->branch_id)
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    private function assertPosResourcesBelongToBranch(array $validated, int $branchId): void
    {
        foreach (['pos_register_id' => 'pos_registers', 'pos_session_id' => 'pos_sessions'] as $key => $table) {
            if (empty($validated[$key])) {
                continue;
            }

            abort_unless(
                \Illuminate\Support\Facades\DB::table($table)
                    ->where('id', $validated[$key])
                    ->where('branch_id', $branchId)
                    ->exists(),
                422,
                'The selected POS resource does not belong to this branch.'
            );
        }
    }

    private function canClaimAssignedTerminal(Request $request, PosTerminalDevice $device): bool
    {
        $user = $request->user();
        if (! $user || ! $device->branch_id) {
            return false;
        }

        if ($user->assignedToBranch()) {
            return $user->branchId() === (int) $device->branch_id;
        }

        if (! $user->assignedToTenant()) {
            return false;
        }

        return \Modules\Branch\Models\Branch::query()
            ->withoutGlobalScopes()
            ->whereKey($device->branch_id)
            ->where('tenant_id', $user->tenantId())
            ->exists();
    }

    private function userTenantId(Request $request): ?int
    {
        $user = $request->user();

        return $user?->tenantId()
            ?? $user?->branch()
                ->withoutGlobalScopes()
                ->value('tenant_id');
    }

    private function userBranchId(Request $request): ?int
    {
        return $request->user()?->branchId();
    }

    private function terminalMeta(array $meta): ?array
    {
        $allowed = [
            'app_started_at',
            'app_uptime_seconds',
            'build_mode',
            'heartbeat_interval_seconds',
            'screen',
            'battery_level',
            'battery_charging',
            'os_version',
            'device_model',
            'crash_count',
        ];

        $sanitized = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $meta) && $meta[$key] !== null && $meta[$key] !== '') {
                $sanitized[$key] = $meta[$key];
            }
        }

        return empty($sanitized) ? null : $sanitized;
    }
}
