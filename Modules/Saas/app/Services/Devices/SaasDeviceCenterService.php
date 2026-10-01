<?php

namespace Modules\Saas\Services\Devices;

use Illuminate\Support\Facades\Schema;
use Modules\Pos\Models\PosTerminalDevice;
use Modules\Printer\Models\PrintAgent;
use Modules\Saas\Models\Tenant;

class SaasDeviceCenterService
{
    public function overview(): array
    {
        $terminals = Schema::hasTable('pos_terminal_devices')
            ? PosTerminalDevice::query()->withoutGlobalScopes()->with(['branch:id,tenant_id,name', 'branch.tenant:id,name', 'posRegister:id,name'])->latest('last_seen_at')->limit(200)->get()
            : collect();
        $terminalRows = $terminals->map(function (PosTerminalDevice $device) {
            $row = $device->toStatusPayload((int) config('pos.fleet.offline_after_seconds', 90), config('pos.fleet.min_app_version'));
            $assignmentPending = (bool) data_get($device->meta, 'assignment.pending_claim', false);
            $configurationIssue = ! $device->branch_id
                ? 'Terminal is not assigned to a restaurant branch.'
                : ($assignmentPending ? 'Waiting for an account from the assigned branch to claim this terminal.' : null);

            return [
                ...$row,
                'type' => 'pos_terminal',
                'tenant_id' => $device->branch?->tenant_id,
                'tenant' => $device->branch?->tenant?->name,
                'assignment_pending' => $assignmentPending,
                'configuration_issue' => $configurationIssue,
                'health_status' => $configurationIssue ? 'warning' : $row['health_status'],
            ];
        })->values();

        $agents = Schema::hasTable('print_agents')
            ? PrintAgent::query()->withoutGlobalScopes()->with(['branch:id,tenant_id,name', 'branch.tenant:id,name'])->latest('last_seen_at')->limit(200)->get()
            : collect();
        $agentCutoff = now()->subMinutes(2);
        $agentRows = $agents->map(function (PrintAgent $agent) use ($agentCutoff) {
            $value = fn (string $key) => $agent->getAttributes()[$key] ?? null;
            $inventory = collect($agent->printer_inventory ?? []);
            $isOnline = $agent->is_active && $agent->last_seen_at?->gte($agentCutoff);
            $hasPrinter = $inventory->isNotEmpty();
            $healthStatus = $value('last_error')
                ? 'warning'
                : (! $isOnline ? 'offline' : ($hasPrinter ? 'healthy' : 'warning'));

            return [
            'id' => $agent->id, 'type' => 'print_agent', 'name' => $agent->name, 'device_id' => $agent->agent_id,
            'tenant_id' => $agent->branch?->tenant_id, 'tenant' => $agent->branch?->tenant?->name,
            'branch_id' => $agent->branch_id, 'branch_name' => $agent->branch?->name,
            'status' => $isOnline ? 'online' : 'offline',
            'is_disabled' => ! $agent->is_active, 'app_version' => $value('version'), 'platform' => $value('platform'),
            'machine_name' => $value('machine_name'), 'queue_status' => $agent->queue_status ?? [],
            'printer_count' => $inventory->count(),
            'configuration_issue' => $isOnline && ! $hasPrinter ? 'No printers reported by this agent.' : null,
            'last_error' => $value('last_error'), 'last_seen_at' => $agent->last_seen_at?->toIso8601String(),
            'last_sync_at' => $value('last_print_success_at') ? \Carbon\Carbon::parse($value('last_print_success_at'))->toIso8601String() : null,
            'health_status' => $healthStatus,
        ]; })->values();

        $devices = $terminalRows->concat($agentRows)->sortByDesc('last_seen_at')->values();
        return [
            'summary' => [
                'total' => $devices->count(), 'terminals' => $terminalRows->count(), 'print_agents' => $agentRows->count(),
                'online' => $devices->where('status', 'online')->count(), 'offline' => $devices->where('status', 'offline')->count(),
                'disabled' => $devices->where('is_disabled', true)->count(),
                'needs_attention' => $devices->filter(fn ($device) => in_array($device['status'], ['offline', 'error'], true) || in_array($device['health_status'] ?? null, ['warning', 'critical'], true))->count(),
            ],
            'minimum_terminal_version' => config('pos.fleet.min_app_version'),
            'devices' => $devices,
            'restaurants' => Tenant::query()->withoutGlobalScopes()->withoutGlobalActive()->with(['branches' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'tenant_id', 'name', 'is_active')])->select('id', 'name')->orderBy('name')->get()
                ->map(fn (Tenant $tenant) => ['id' => $tenant->id, 'name' => $tenant->name, 'branches' => $tenant->branches->map(fn ($branch) => ['id' => $branch->id, 'name' => $branch->name, 'active' => (bool) $branch->is_active])->values()])->values(),
        ];
    }
}
