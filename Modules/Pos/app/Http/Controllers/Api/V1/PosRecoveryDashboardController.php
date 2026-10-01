<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Pos\Models\PosOfflineOrder;
use Modules\Pos\Models\PosTerminalDevice;
use Modules\Pos\Http\Controllers\Api\V1\Concerns\BuildsRecoveryPaymentSummary;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Models\PrintJob;

class PosRecoveryDashboardController
{
    use BuildsRecoveryPaymentSummary;

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'offline_after_seconds' => 'nullable|integer|min:30|max:3600',
            'agent_offline_minutes' => 'nullable|integer|min:1|max:120',
        ]);

        $branchId = $validated['branch_id'] ?? null;
        $offlineAfterSeconds = (int) ($validated['offline_after_seconds'] ?? 90);
        $agentOfflineMinutes = (int) ($validated['agent_offline_minutes'] ?? 5);
        $terminalCutoff = now()->subSeconds($offlineAfterSeconds);
        $agentCutoff = now()->subMinutes($agentOfflineMinutes);

        $terminal = $this->terminalSummary($branchId, $terminalCutoff, $offlineAfterSeconds);
        $offline = $this->offlineOrderSummary($branchId);
        $print = $this->printSummary($branchId, $agentCutoff);
        $payment = $this->paymentSummary($branchId);
        $risks = $this->risks($terminal, $offline, $print, $payment);

        return response()->json([
            'data' => [
                'status' => $this->overallStatus($risks),
                'checked_at' => now()->toISOString(),
                'storage_ready' => [
                    'terminal_devices' => $terminal['storage_ready'],
                    'offline_orders' => $offline['storage_ready'],
                    'print_jobs' => $print['jobs_storage_ready'],
                    'print_agents' => $print['agents_storage_ready'],
                    'payments' => $payment['storage_ready'],
                ],
                'thresholds' => [
                    'terminal_offline_after_seconds' => $offlineAfterSeconds,
                    'print_agent_offline_after_minutes' => $agentOfflineMinutes,
                    'offline_order_stale_after_minutes' => 10,
                    'print_job_stale_after_minutes' => 5,
                ],
                'summary' => [
                    ...$terminal['summary'],
                    ...$offline['summary'],
                    ...$print['summary'],
                    ...$payment['summary'],
                ],
                'risks' => $risks,
                'terminals' => $terminal['items'],
                'offline_orders' => $offline['items'],
                'print_jobs' => $print['jobs'],
                'print_agents' => $print['agents'],
                'payments' => $payment['items'],
            ],
        ]);
    }

    private function terminalSummary(?int $branchId, mixed $cutoff, int $offlineAfterSeconds): array
    {
        if (! Schema::hasTable('pos_terminal_devices')) {
            return [
                'storage_ready' => false,
                'summary' => [
                    'terminal_total' => 0,
                    'terminal_online' => 0,
                    'terminal_offline' => 0,
                    'terminal_syncing' => 0,
                    'terminal_error' => 0,
                    'terminal_disabled' => 0,
                    'terminal_queue_count' => 0,
                ],
                'items' => [],
            ];
        }

        $baseQuery = PosTerminalDevice::query()
            ->with(['branch:id,name', 'posRegister:id,name'])
            ->when($branchId, fn($query) => $query->where('branch_id', $branchId));

        $total = (clone $baseQuery)->count();
        $online = (clone $baseQuery)->where('status', 'online')->where('last_seen_at', '>=', $cutoff)->count();
        $syncing = (clone $baseQuery)->where('status', 'syncing')->where('last_seen_at', '>=', $cutoff)->count();
        $error = (clone $baseQuery)->where('status', 'error')->where('last_seen_at', '>=', $cutoff)->count();
        $offline = (clone $baseQuery)
            ->where(fn($query) => $query
                ->where('status', 'offline')
                ->orWhereNull('last_seen_at')
                ->orWhere('last_seen_at', '<', $cutoff))
            ->count();
        $queueCount = (int) (clone $baseQuery)->sum(DB::raw('local_queue_count + server_queue_count'));
        $queueDevices = (clone $baseQuery)
            ->where(fn($query) => $query
                ->where('local_queue_count', '>', 0)
                ->orWhere('server_queue_count', '>', 0))
            ->count();
        $hasDisabledColumn = Schema::hasColumn('pos_terminal_devices', 'is_disabled');
        $disabled = $hasDisabledColumn
            ? (clone $baseQuery)->where('is_disabled', true)->count()
            : 0;
        $unhealthyQuery = (clone $baseQuery)
            ->where(function ($query) use ($cutoff, $hasDisabledColumn) {
                $query
                    ->where('status', 'error')
                    ->orWhere('status', 'syncing')
                    ->orWhere('local_queue_count', '>', 0)
                    ->orWhere('server_queue_count', '>', 0)
                    ->orWhereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', $cutoff);

                if ($hasDisabledColumn) {
                    $query->orWhere('is_disabled', true);
                }
            });

        $unhealthy = $unhealthyQuery->count();
        $minAppVersion = config('pos.fleet.min_app_version');

        $items = (clone $baseQuery)
            ->where(function ($query) use ($cutoff, $hasDisabledColumn) {
                $query
                    ->where('status', 'error')
                    ->orWhere('status', 'syncing')
                    ->orWhere('local_queue_count', '>', 0)
                    ->orWhere('server_queue_count', '>', 0)
                    ->orWhereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', $cutoff);

                if ($hasDisabledColumn) {
                    $query->orWhere('is_disabled', true);
                }
            })
            ->latest('last_seen_at')
            ->limit(8)
            ->get()
            ->map(fn(PosTerminalDevice $device) => $device->toStatusPayload($offlineAfterSeconds, $minAppVersion))
            ->values()
            ->all();

        return [
            'storage_ready' => true,
            'summary' => [
                'terminal_total' => $total,
                'terminal_online' => $online,
                'terminal_offline' => $offline,
                'terminal_syncing' => $syncing,
                'terminal_error' => $error,
                'terminal_disabled' => $disabled,
                'terminal_queue_count' => $queueCount,
                'terminal_queue_devices' => $queueDevices,
                'terminal_unhealthy' => $unhealthy,
            ],
            'items' => $items,
        ];
    }

    private function offlineOrderSummary(?int $branchId): array
    {
        if (! Schema::hasTable('pos_offline_orders')) {
            return [
                'storage_ready' => false,
                'summary' => [
                    'offline_pending' => 0,
                    'offline_processing' => 0,
                    'offline_retrying' => 0,
                    'offline_failed' => 0,
                    'offline_synced' => 0,
                    'offline_stale' => 0,
                ],
                'items' => [],
            ];
        }

        $baseQuery = PosOfflineOrder::query()
            ->when($branchId, fn($query) => $query->where('branch_id', $branchId));
        $activeQuery = (clone $baseQuery)->whereNull('synced_at');

        $items = (clone $activeQuery)
            ->whereIn('sync_status', ['failed', 'retrying', 'processing', 'pending'])
            ->oldest('created_at')
            ->limit(8)
            ->get()
            ->map(fn(PosOfflineOrder $order) => [
                'id' => $order->id,
                'offline_id' => $order->offline_id,
                'reference_no' => $order->reference_no,
                'device_id' => $order->device_id,
                'branch_id' => $order->branch_id,
                'sync_status' => $order->sync_status,
                'attempts' => $order->attempts,
                'total' => (float) $order->total,
                'last_sync_error' => $order->last_sync_error,
                'last_sync_attempt_at' => $order->last_sync_attempt_at?->toISOString(),
                'created_at' => $order->created_at?->toISOString(),
            ])
            ->values()
            ->all();

        return [
            'storage_ready' => true,
            'summary' => [
                'offline_pending' => (clone $activeQuery)->where('sync_status', 'pending')->count(),
                'offline_processing' => (clone $activeQuery)->where('sync_status', 'processing')->count(),
                'offline_retrying' => (clone $activeQuery)->where('sync_status', 'retrying')->count(),
                'offline_failed' => (clone $activeQuery)->where('sync_status', 'failed')->count(),
                'offline_synced' => (clone $baseQuery)->whereNotNull('synced_at')->count(),
                'offline_stale' => (clone $activeQuery)
                    ->where('created_at', '<', now()->subMinutes(10))
                    ->count(),
            ],
            'items' => $items,
        ];
    }

    private function printSummary(?int $branchId, mixed $agentCutoff): array
    {
        $jobs = $this->printJobSummary($branchId);
        $agents = $this->printAgentSummary($branchId, $agentCutoff);

        return [
            'jobs_storage_ready' => $jobs['storage_ready'],
            'agents_storage_ready' => $agents['storage_ready'],
            'summary' => [
                ...$jobs['summary'],
                ...$agents['summary'],
            ],
            'jobs' => $jobs['items'],
            'agents' => $agents['items'],
        ];
    }

    private function printJobSummary(?int $branchId): array
    {
        if (! Schema::hasTable('print_jobs')) {
            return [
                'storage_ready' => false,
                'summary' => [
                    'print_pending' => 0,
                    'print_ready_to_print' => 0,
                    'print_awaiting_agent' => 0,
                    'print_failed' => 0,
                    'print_success' => 0,
                    'print_stale' => 0,
                ],
                'items' => [],
            ];
        }

        $now = now();
        $baseQuery = PrintJob::query()
            ->when($branchId, fn($query) => $query->where('branch_id', $branchId));
        $pendingStatus = PrintJobStatus::Pending->value;
        $failedStatus = PrintJobStatus::Failed->value;
        $successStatus = PrintJobStatus::Success->value;
        $pendingQuery = (clone $baseQuery)->where('status', $pendingStatus);

        $items = (clone $baseQuery)
            ->whereIn('status', [$failedStatus, $pendingStatus])
            ->latest('created_at')
            ->limit(8)
            ->get()
            ->map(fn(PrintJob $job) => [
                'id' => $job->id,
                'status' => $job->status instanceof PrintJobStatus ? $job->status->value : (string) $job->status,
                'branch_id' => $job->branch_id,
                'content_type' => data_get($job->printer_config, 'content_type')
                    ?? data_get($job->printer_config, 'print_type')
                    ?? data_get($job->printer_config, 'type'),
                'printer_name' => data_get($job->printer_config, 'name')
                    ?? data_get($job->printer_config, 'printer_name')
                    ?? data_get($job->printer_config, 'spooler_name'),
                'agent_id' => data_get($job->printer_config, 'agent_id'),
                'claimed_by' => $job->claimed_by,
                'lease_until' => $job->lease_until?->toISOString(),
                'error_message' => $job->error_message,
                'created_at' => $job->created_at?->toISOString(),
                'age_seconds' => $job->created_at ? (int) $job->created_at->diffInSeconds($now, true) : null,
            ])
            ->values()
            ->all();

        return [
            'storage_ready' => true,
            'summary' => [
                'print_pending' => (clone $pendingQuery)->count(),
                'print_ready_to_print' => (clone $pendingQuery)
                    ->where(fn($query) => $query
                        ->whereNull('claimed_by')
                        ->orWhereNull('lease_until')
                        ->orWhere('lease_until', '<', $now))
                    ->count(),
                'print_awaiting_agent' => (clone $pendingQuery)
                    ->whereNotNull('claimed_by')
                    ->where('lease_until', '>=', $now)
                    ->count(),
                'print_failed' => (clone $baseQuery)->where('status', $failedStatus)->count(),
                'print_success' => (clone $baseQuery)->where('status', $successStatus)->count(),
                'print_stale' => (clone $pendingQuery)
                    ->where('created_at', '<', now()->subMinutes(5))
                    ->count(),
            ],
            'items' => $items,
        ];
    }

    private function printAgentSummary(?int $branchId, mixed $agentCutoff): array
    {
        if (! Schema::hasTable('print_agents')) {
            return [
                'storage_ready' => false,
                'summary' => [
                    'print_agents_total' => 0,
                    'print_agents_online' => 0,
                    'print_agents_offline' => 0,
                ],
                'items' => [],
            ];
        }

        $baseQuery = PrintAgent::query()
            ->withoutGlobalActive()
            ->when($branchId, fn($query) => $query->where('branch_id', $branchId));
        $activeQuery = (clone $baseQuery)->where('is_active', true);

        $items = (clone $activeQuery)
            ->where(fn($query) => $query
                ->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<', $agentCutoff)
                ->orWhereNotNull('last_error'))
            ->latest('last_seen_at')
            ->limit(8)
            ->get()
            ->map(fn(PrintAgent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'agent_id' => $agent->agent_id,
                'branch_id' => $agent->branch_id,
                'status' => $agent->last_seen_at?->greaterThanOrEqualTo($agentCutoff) ? 'online' : 'offline',
                'last_seen_at' => $agent->last_seen_at?->toISOString(),
                'queue_status' => $agent->queue_status,
                'last_error' => $agent->last_error,
            ])
            ->values()
            ->all();

        return [
            'storage_ready' => true,
            'summary' => [
                'print_agents_total' => (clone $activeQuery)->count(),
                'print_agents_online' => (clone $activeQuery)->where('last_seen_at', '>=', $agentCutoff)->count(),
                'print_agents_offline' => (clone $activeQuery)
                    ->where(fn($query) => $query
                        ->whereNull('last_seen_at')
                        ->orWhere('last_seen_at', '<', $agentCutoff))
                    ->count(),
            ],
            'items' => $items,
        ];
    }

    private function risks(array $terminal, array $offline, array $print, array $payment): array
    {
        $summary = [
            ...$terminal['summary'],
            ...$offline['summary'],
            ...$print['summary'],
            ...$payment['summary'],
        ];

        return collect([
            $this->translatedRisk('critical', 'offline_failed', (int) $summary['offline_failed']),
            $this->translatedRisk('warning', 'offline_stale', (int) $summary['offline_stale']),
            $this->translatedRisk('critical', 'print_failed', (int) $summary['print_failed']),
            $this->translatedRisk('warning', 'print_stale', (int) $summary['print_stale']),
            $this->translatedRisk('critical', 'terminal_error', (int) $summary['terminal_error']),
            $this->translatedRisk('warning', 'terminal_offline', (int) $summary['terminal_offline']),
            $this->translatedRisk('warning', 'terminal_queue', (int) $summary['terminal_queue_devices']),
            $this->translatedRisk('warning', 'agent_offline', (int) $summary['print_agents_offline']),
            $this->translatedRisk('critical', 'payment_failed', (int) $summary['payment_failed']),
            $this->translatedRisk('warning', 'payment_pending', (int) $summary['payment_pending'] + (int) $summary['payment_gateway_pending']),
        ])->filter()->values()->all();
    }

    private function translatedRisk(string $severity, string $code, int $count): ?array
    {
        if ($count <= 0) {
            return null;
        }

        $baseKey = "pos::pos_viewer.recovery_dashboard.risk.{$code}";

        return [
            'severity' => $severity,
            'code' => $code,
            'title' => __($baseKey . '.title'),
            'detail' => __($baseKey . '.detail', ['count' => $count]),
            'action' => __($baseKey . '.action'),
        ];
    }

    private function overallStatus(array $risks): string
    {
        $severities = Collection::make($risks)->pluck('severity');

        if ($severities->contains('critical')) {
            return 'critical';
        }

        return $severities->contains('warning') ? 'warning' : 'ok';
    }
}
