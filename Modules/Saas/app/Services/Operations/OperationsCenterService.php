<?php

namespace Modules\Saas\Services\Operations;

use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Redis;
use Modules\Saas\Models\Tenant;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;
use Modules\Saas\Services\CustomerSuccess\CustomerSuccessService;
use Modules\Saas\Services\Provisioning\SaasHealthService;
use Modules\Saas\Services\Provisioning\SaasServerAutomationService;
use Symfony\Component\Process\Process;

class OperationsCenterService
{
    public function dashboard(
        SaasHealthService $tenantHealth,
        SaasServerAutomationService $serverAutomation,
        CustomerSuccessService $customerSuccess,
        SaasRealtimeIncidentService $realtimeIncidents,
        SaasAlertStateService $alertStates
    ): array {
        return Cache::remember('saas:operations-center:v1', now()->addSeconds(30), function () use (
            $tenantHealth,
            $serverAutomation,
            $customerSuccess,
            $realtimeIncidents,
            $alertStates
        ) {
            $tenants = $this->tenants();
            $customerData = $this->safe(fn () => $customerSuccess->dashboard(), []);
            $healthRows = collect($this->safe(fn () => $tenantHealth->all(), []));
            $server = $this->safe(fn () => $serverAutomation->health(), []);

            $platform = $this->platform($server, $healthRows);

            $alerts = $this->alerts($customerData, $realtimeIncidents, $alertStates, $platform);

            return [
                'generated_at' => now()->toIso8601String(),
                'platform' => $platform,
                'tenants' => $this->tenantStatus($tenants, $customerData),
                'business' => $this->business(),
                'alerts' => $alerts,
                'alert_history' => $alertStates->history(30),
                'customer_success' => $this->customerSuccess($customerData),
                'activity' => $this->activity(),
                'queue_center' => $this->queueCenter(),
                'devices' => $this->devices(),
                'printing' => $this->printing(),
                'communication' => $this->communication(),
                'deployment' => $this->deployment($server),
                'analytics' => $this->analytics(),
            ];
        });
    }

    private function tenants(): Collection
    {
        return Tenant::query()
            ->withoutGlobalScopes()
            ->withoutGlobalActive()
            ->withTrashed()
            ->get();
    }

    private function platform(array $server, Collection $healthRows): array
    {
        $components = [
            $this->component('database', 'Database', $this->databaseOk(), 'Core data store'),
            $this->component('storage', 'Storage', is_writable(storage_path()), 'Local storage writable'),
            $this->component('queue', 'Queue', $this->queueStatus(), $this->queueLabel()),
            $this->component('redis', 'Redis', $this->redisStatus(), 'Cache and queue transport'),
            $this->component('reverb', 'Reverb / WebSocket', $this->reverbStatus($server), $this->reverbLabel($server)),
            $this->component('cron', 'Scheduler', $this->schedulerStatus(), $this->schedulerLabel()),
            $this->component('supervisor', 'Supervisor', (bool) data_get($server, 'processes.supervisorctl.available'), 'Worker process manager'),
            $this->component('backups', 'Backups', $this->backupCount() > 0, $this->backupCount().' backups found'),
        ];

        $healthy = collect($components)->where('status', 'ok')->count();
        $score = (int) round(($healthy / max(1, count($components))) * 100);

        return [
            'score' => $score,
            'status' => $score >= 85 ? 'healthy' : ($score >= 65 ? 'warning' : 'critical'),
            'response_time_ms' => $this->responseTimeMs(),
            'cpu' => $this->loadAverage(),
            'ram' => $this->memoryUsage(),
            'disk' => $this->diskUsage(),
            'network' => [
                'status' => 'observed',
                'message' => 'Measured through API response and realtime health.',
            ],
            'components' => $components,
            'server' => $server,
        ];
    }

    private function tenantStatus(Collection $tenants, array $customerData): array
    {
        $customers = collect(data_get($customerData, 'customers', []));
        $statuses = $customers->groupBy('status')->map->count();
        $subscriptions = $this->rows('tenant_subscriptions');

        // tenants() loads withTrashed() so lifecycle history stays available,
        // but a deleted restaurant is not part of the portfolio. Counting it in
        // the total while online/offline excluded it made total != online +
        // offline, and put the Executive Dashboard out of step with the
        // Restaurant Registry, which never lists deleted rows.
        $live = $tenants->whereNull('deleted_at');

        return [
            'total' => $live->count(),
            'online' => $live->where('is_active', true)->count(),
            'offline' => $live->where('is_active', false)->count(),
            'provisioning' => $statuses->get('provisioning', 0) + $statuses->get('onboarding', 0) + $statuses->get('training', 0),
            'suspended' => $statuses->get('cancelled', 0),
            'grace_period' => 0,
            'trial' => $statuses->get('trial', 0),
            'renewal_due' => $statuses->get('renewal', 0),
            'expired' => $subscriptions->whereIn('status', ['expired', 'cancelled'])->count(),
            'high_risk' => $customers->filter(fn ($row) => data_get($row, 'health.level') === 'critical')->count(),
            'rows' => $customers->take(8)->values(),
        ];
    }

    private function business(): array
    {
        $today = now()->toDateString();
        $orders = $this->dateQuery('orders', $today);
        $payments = $this->dateQuery('payments', $today);
        $subscriptions = $this->rows('tenant_subscriptions');

        $mrr = (float) $subscriptions->whereIn('status', ['active', 'trial', 'grace'])->sum(
            fn ($row) => (float) ($row->amount ?? $row->price ?? 0)
        );

        return [
            'today_orders' => (int) $orders?->count() ?: 0,
            'today_revenue' => $this->sumQuery($orders, ['grand_total', 'total', 'amount', 'net_total']),
            'payments' => (int) $payments?->count() ?: 0,
            'payment_amount' => $this->sumQuery($payments, ['amount', 'paid_amount', 'total']),
            // Same reason as the customers chart: there is no `customers`
            // table, so this counted nothing and always reported 0.
            'new_customers' => $this->newCustomersOn($today),
            'renewals' => $subscriptions->filter(fn ($row) => $this->withinDays($row->ends_at ?? null, 7))->count(),
            'trials_started' => $subscriptions->filter(fn ($row) => $this->sameDate($row->created_at ?? null, $today) && filled($row->trial_ends_at ?? null))->count(),
            'trials_ending' => $subscriptions->filter(fn ($row) => $this->withinDays($row->trial_ends_at ?? null, 7))->count(),
            'mrr' => $mrr,
            'arr' => $mrr * 12,
        ];
    }

    private function alerts(
        array $customerData,
        SaasRealtimeIncidentService $realtimeIncidents,
        SaasAlertStateService $alertStates,
        array $platform
    ): array
    {
        $alerts = collect();
        $failedJobs = Schema::hasTable('failed_jobs') && Schema::hasColumn('failed_jobs', 'failed_at')
            ? (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count()
            : 0;
        $pendingJobs = $this->count('jobs');
        $failedPrints = $this->countWhereIn('print_jobs', 'status', ['failed', 'error']);
        $failedMessages = $this->countWhereIn('notification_logs', 'status', ['failed', 'error']);

        collect(data_get($platform, 'components', []))
            ->filter(fn (array $component) => ($component['status'] ?? 'ok') !== 'ok')
            ->each(function (array $component) use ($alerts) {
                $key = (string) ($component['key'] ?? 'platform');
                $critical = in_array($key, ['database', 'queue', 'redis', 'reverb', 'supervisor'], true);
                $alerts->push($this->alert(
                    'platform_'.$key,
                    $critical ? 'critical' : 'warning',
                    ($component['label'] ?? 'Platform component').' requires attention',
                    (string) ($component['message'] ?? 'Health check failed.')
                ));
            });

        if ($failedJobs > 0) {
            $alerts->push($this->alert('queue_failed', 'warning', 'Recent queue failures detected', "{$failedJobs} job(s) failed in the last 24 hours and may need retry."));
        }
        if ($pendingJobs > 50) {
            $alerts->push($this->alert('queue_backlog', 'warning', 'Queue backlog increasing', "{$pendingJobs} jobs are waiting."));
        }
        if ($failedPrints > 0) {
            $alerts->push($this->alert('print_failed', 'critical', 'Printer failures', "{$failedPrints} print jobs failed."));
        }
        if ($failedMessages > 0) {
            $alerts->push($this->alert('message_failed', 'warning', 'Message delivery failures', "{$failedMessages} notifications failed."));
        }

        $this->deviceAlerts()->each(fn (array $alert) => $alerts->push($alert));

        collect(data_get($customerData, 'tasks', []))
            ->take(6)
            ->each(fn ($task) => $alerts->push($this->alert(
                'customer_success',
                data_get($task, 'severity', 'warning'),
                data_get($task, 'title', 'Customer needs attention'),
                data_get($task, 'message', 'Review customer success task.'),
                data_get($task, 'tenant_id')
            )));

        $realtimeIncidents->unresolvedByTenant(12)
            ->each(fn (array $incident) => $alerts->push($this->alert(
                'realtime_'.$incident['category'],
                $incident['severity'],
                'Realtime agent incident',
                "{$incident['incident_count']} unresolved {$incident['category']} incident(s) across this restaurant's agents.",
                $incident['tenant_id']
            )));

        return $alertStates->decorate($alerts->sortByDesc(fn ($row) => $row['priority'])->values()->take(12));
    }

    private function customerSuccess(array $customerData): array
    {
        return [
            'summary' => data_get($customerData, 'summary', []),
            'needs_follow_up' => collect(data_get($customerData, 'tasks', []))->take(8)->values(),
            'renewals' => collect(data_get($customerData, 'renewals', []))->take(8)->values(),
        ];
    }

    private function activity(): array
    {
        $rows = collect();
        $activityTable = config('activitylog.table_name', 'activity_log');
        if (Schema::hasTable($activityTable)) {
            $rows = DB::table($activityTable)
                ->latest('created_at')
                ->limit(20)
                ->get()
                ->map(fn ($row) => [
                    'type' => $this->humanizeActivityKey($row->event ?? $row->log_name ?? 'activity'),
                    'title' => $this->activityTitle($row),
                    'entity' => $row->subject_type ?? null,
                    'entity_id' => $row->subject_id ?? null,
                    'created_at' => $row->created_at ?? null,
                ]);
        }

        if ($rows->isEmpty() && Schema::hasTable('saas_provisioning_runs')) {
            $rows = DB::table('saas_provisioning_runs')
                ->latest('updated_at')
                ->limit(20)
                ->get()
                ->map(fn ($row) => [
                    'type' => 'provisioning',
                    'title' => 'Provisioning '.$row->status,
                    'entity' => 'tenant',
                    'entity_id' => $row->tenant_id ?? null,
                    'created_at' => $row->updated_at ?? null,
                ]);
        }

        return $rows->values()->all();
    }

    /**
     * Readable title for a Live Activity row.
     *
     * activity_log.description holds a translation key ("admin::messages
     * .resource_updated", "feature_flag_updated"), which this feed previously
     * emitted verbatim. Translate it the way ActivityLogResource does, and
     * humanise anything that has no translation so a key never reaches the UI.
     */
    private function activityTitle(object $row): string
    {
        $description = trim((string) ($row->description ?? ''));
        if ($description === '') {
            return 'Activity recorded';
        }

        $properties = json_decode((string) ($row->properties ?? ''), true);
        $params = array_map(
            static fn ($value) => is_string($value) ? __($value) : $value,
            is_array($properties) ? ($properties['trans_params'] ?? []) : []
        );

        $translated = __($description, $params);
        if (! is_string($translated)) {
            $translated = $description;
        }

        // Keys never contain spaces; a real sentence does.
        return $translated === $description && ! str_contains($description, ' ')
            ? $this->humanizeActivityKey($description)
            : $translated;
    }

    private function humanizeActivityKey(string $key): string
    {
        return Str::headline(Str::afterLast($key, '.'));
    }

    private function devices(): array
    {
        if (! Schema::hasTable('pos_terminal_devices')) {
            return $this->emptyStatus();
        }

        $query = DB::table('pos_terminal_devices');
        if (Schema::hasColumn('pos_terminal_devices', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        $rows = $query->get();
        $online = $rows->filter(fn ($row) => $this->seenRecently($row->last_seen_at ?? null))->count();

        return [
            'total' => $rows->count(),
            'online' => $online,
            'offline' => $rows->count() - $online,
            'needs_update' => 0,
            'inactive' => $rows->where('status', 'inactive')->count(),
            'battery_low' => $rows->filter(function ($row) {
                $battery = data_get(json_decode($row->meta ?? '{}', true), 'battery_level');
                return is_numeric($battery) && (int) $battery < 20;
            })->count(),
            'last_sync' => $rows->max('last_sync_at'),
            'queue_depth' => (int) $rows->sum('local_queue_count') + (int) $rows->sum('server_queue_count'),
        ];
    }

    private function printing(): array
    {
        $jobs = $this->recentRows('print_jobs');
        $agents = $this->recentRows('print_agents');
        $onlineAgents = $agents->filter(fn ($row) => $this->seenRecently($row->last_seen_at ?? null))->count();

        return [
            'queue_size' => $this->countWhereIn('print_jobs', 'status', ['pending', 'queued', 'processing']),
            'failed_jobs' => $this->countWhereIn('print_jobs', 'status', ['failed', 'error']),
            'completed_jobs' => $this->countWhereIn('print_jobs', 'status', ['completed', 'printed', 'success']),
            'printing_jobs' => $this->countWhereIn('print_jobs', 'status', ['printing', 'processing']),
            'offline_printers' => max(0, $agents->count() - $onlineAgents),
            'online_agents' => $onlineAgents,
            'average_print_time_ms' => $this->averagePrintTime($jobs),
            'last_print_at' => $jobs->max('completed_at') ?: $jobs->max('updated_at') ?: $jobs->max('created_at'),
        ];
    }

    private function queueCenter(): array
    {
        $jobs = $this->recentRows('jobs', 5000);
        $failed = $this->recentRows('failed_jobs', 5000);
        $now = now();
        $oldest = $jobs
            ->pluck('created_at')
            ->filter()
            ->map(fn ($date) => $this->asCarbon($date))
            ->filter()
            ->sort()
            ->first();
        $queueGroups = $jobs->groupBy(fn ($row) => (string) ($row->queue ?? 'default'));
        $horizon = $this->horizonSnapshot();
        $supervisor = $this->supervisorWorkerSnapshot();
        if (! data_get($horizon, 'available', false) && $supervisor['available']) {
            $horizon['workers_online'] = $supervisor['online'];
            $horizon['workers_busy'] = $jobs->isNotEmpty() ? min($supervisor['online'], 1) : 0;
            $horizon['workers_idle'] = max(0, $supervisor['online'] - $horizon['workers_busy']);
        }
        $pending = $jobs->count();
        $recentFailedCount = $failed->filter(fn ($row) => $this->asCarbon($row->failed_at ?? null)?->greaterThan(now()->subDay()) ?? false)->count();
        $longestWaitSeconds = $oldest ? max(0, $oldest->diffInSeconds($now)) : 0;
        $status = match (true) {
            $longestWaitSeconds > 300 || ($pending > 0 && (int) data_get($horizon, 'workers_online', 0) === 0) => 'critical',
            $recentFailedCount > 0 || $pending > 50 || $longestWaitSeconds > 10 => 'warning',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'health_label' => $status === 'healthy' ? 'Queues are flowing' : ($status === 'warning' ? 'Queue delay detected' : 'Queue requires action'),
            'pending_jobs' => $pending,
            'failed_jobs' => $recentFailedCount,
            'historical_failed_jobs' => $failed->count(),
            'retry_count' => $jobs->sum(fn ($row) => (int) ($row->attempts ?? 0)),
            'completed_jobs' => (int) data_get($horizon, 'completed_jobs', 0),
            'queue_size' => $pending,
            'longest_wait_seconds' => $longestWaitSeconds,
            'longest_wait_label' => $this->durationLabel($longestWaitSeconds),
            'average_processing_time_ms' => (int) data_get($horizon, 'average_processing_time_ms', 0),
            'throughput_per_minute' => (float) data_get($horizon, 'throughput_per_minute', 0),
            'workers_online' => (int) data_get($horizon, 'workers_online', 0),
            'workers_busy' => (int) data_get($horizon, 'workers_busy', 0),
            'workers_idle' => (int) data_get($horizon, 'workers_idle', 0),
            'worker_uptime_seconds' => (int) data_get($horizon, 'worker_uptime_seconds', 0),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'queues' => $queueGroups->map(fn (Collection $rows, string $name) => [
                'name' => $name,
                'pending' => $rows->count(),
                'retry_count' => $rows->sum(fn ($row) => (int) ($row->attempts ?? 0)),
                'oldest_wait_seconds' => $this->oldestWaitSeconds($rows),
            ])->values()->all(),
            'horizon' => $horizon,
            'supervisor' => $supervisor,
            'last_checked_at' => $now->toIso8601String(),
        ];
    }

    private function communication(): array
    {
        $logs = $this->recentRows('notification_logs');

        return [
            'email_queue' => $logs->where('channel', 'email')->whereIn('status', ['pending', 'queued'])->count(),
            'whatsapp_queue' => $logs->where('channel', 'whatsapp')->whereIn('status', ['pending', 'queued'])->count(),
            'notification_queue' => $logs->where('channel', 'in_app')->whereIn('status', ['pending', 'queued'])->count(),
            'sent' => $logs->whereIn('status', ['sent', 'delivered'])->count(),
            'failed' => $logs->whereIn('status', ['failed', 'error'])->count(),
            'success_rate' => $this->rate($logs->whereIn('status', ['sent', 'delivered'])->count(), max(1, $logs->count())),
        ];
    }

    private function deployment(array $server): array
    {
        return [
            'current_version' => config('app.version', env('APP_VERSION', 'unknown')),
            'environment' => app()->environment(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'pending_deployments' => 0,
            'migration_status' => Schema::hasTable('migrations') ? 'tracked' : 'unknown',
            'rollback_available' => false,
            'automation' => data_get($server, 'automation', []),
        ];
    }

    private function analytics(): array
    {
        return [
            'orders' => $this->series('orders', 'count'),
            'revenue' => $this->series('orders', 'sum', ['grand_total', 'total', 'amount']),
            // There is no `customers` table — a customer is a user holding the
            // Customer role. Pointing series() at a missing table returned an
            // empty set, so the customers chart rendered with no data at all.
            'customers' => $this->customerSeries(),
            'storage' => [
                ['label' => 'Tenant media', 'value' => $this->storageMb('tenants')],
                ['label' => 'Backups', 'value' => $this->storageMb('saas/backups')],
            ],
        ];
    }

    /**
     * New customers per day for the last week.
     *
     * Mirrors CustomerService: a customer is a user holding the Customer role.
     */
    private function customerSeries(): array
    {
        if (! Schema::hasTable('users')) {
            return $this->emptySeries();
        }

        return $this->eachDay(fn (string $date): int => $this->newCustomersOn($date));
    }

    private function newCustomersOn(string $date): int
    {
        if (! Schema::hasTable('users')) {
            return 0;
        }

        return (int) $this->safe(fn () => User::query()
            ->withoutGlobalScopes()
            ->role(DefaultRole::Customer)
            ->whereDate('created_at', $date)
            ->count(), 0);
    }

    /** A zero-filled week, so a chart still renders its axis. */
    private function emptySeries(): array
    {
        return $this->eachDay(static fn (): int => 0);
    }

    private function eachDay(callable $value): array
    {
        return collect(range(6, 0))->map(function (int $days) use ($value) {
            $date = now()->subDays($days)->toDateString();

            return ['date' => $date, 'value' => $value($date)];
        })->all();
    }

    private function series(string $table, string $mode, array $columns = []): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'created_at')) {
            // Return an empty week rather than nothing: a missing table should
            // render a flat chart, not a blank panel with no explanation.
            return $this->emptySeries();
        }

        return collect(range(6, 0))->map(function (int $days) use ($table, $mode, $columns) {
            $date = now()->subDays($days)->toDateString();
            $query = DB::table($table)->whereDate('created_at', $date);
            $value = $mode === 'sum' ? $this->sumQuery($query, $columns) : (int) $query->count();

            return ['date' => $date, 'value' => $value];
        })->all();
    }

    private function component(string $key, string $label, bool $ok, string $message): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $ok ? 'ok' : 'warning', 'message' => $message];
    }

    private function alert(string $type, string $severity, string $title, string $message, mixed $tenantId = null, ?string $key = null): array
    {
        $priority = ['critical' => 100, 'warning' => 70, 'info' => 30][$severity] ?? 50;

        return compact('type', 'severity', 'title', 'message', 'tenantId', 'key') + [
            'priority' => $priority,
            'created_at' => now()->toIso8601String(),
        ];
    }

    private function deviceAlerts(): Collection
    {
        $alerts = collect();
        if (Schema::hasTable('pos_terminal_devices')) {
            $query = DB::table('pos_terminal_devices as device')
                ->leftJoin('branches as branch', 'branch.id', '=', 'device.branch_id')
                ->select('device.id', 'device.device_id', 'device.name', 'device.branch_id', 'device.status', 'device.last_seen_at', 'device.meta', 'branch.tenant_id');
            if (Schema::hasColumn('pos_terminal_devices', 'deleted_at')) $query->whereNull('device.deleted_at');

            $query->get()->each(function ($device) use ($alerts) {
                $meta = json_decode($device->meta ?? '{}', true) ?: [];
                $crashes = (int) data_get($meta, 'crash_count', 0);
                $label = $device->name ?: $device->device_id ?: "Terminal #{$device->id}";
                if (! $device->branch_id) {
                    $alerts->push($this->alert('device_unassigned', 'warning', 'Terminal needs assignment', "{$label} is not assigned to a restaurant branch.", null, "terminal:{$device->id}:unassigned"));
                } elseif (data_get($meta, 'assignment.pending_claim', false)) {
                    $alerts->push($this->alert('device_assignment_pending', 'warning', 'Terminal handoff pending', "{$label} is waiting for an account from its assigned branch.", $device->tenant_id, "terminal:{$device->id}:assignment-pending"));
                }
                if ($crashes > 0) {
                    $alerts->push($this->alert('device_crash', $crashes >= 3 ? 'critical' : 'warning', 'Terminal crashes detected', "{$label} reported {$crashes} application crash(es).", $device->tenant_id, "terminal:{$device->id}:crashes"));
                }
                if (! $this->seenRecently($device->last_seen_at)) {
                    $alerts->push($this->alert('device_offline', 'warning', 'Terminal offline', "{$label} has missed recent heartbeats.", $device->tenant_id, "terminal:{$device->id}:offline"));
                }
                $battery = data_get($meta, 'battery_level');
                if (is_numeric($battery) && (int) $battery <= 20 && ! data_get($meta, 'battery_charging', false)) {
                    $alerts->push($this->alert('device_battery', (int) $battery <= 10 ? 'critical' : 'warning', 'Terminal battery low', "{$label} reports {$battery}% battery.", $device->tenant_id, "terminal:{$device->id}:battery"));
                }
            });
        }

        if (Schema::hasTable('print_agents')) {
            $query = DB::table('print_agents as agent')
                ->leftJoin('branches as branch', 'branch.id', '=', 'agent.branch_id')
                ->select('agent.id', 'agent.agent_id', 'agent.name', 'agent.last_seen_at', 'agent.is_active', 'branch.tenant_id');
            foreach (['printer_inventory', 'queue_status', 'last_error'] as $column) {
                if (Schema::hasColumn('print_agents', $column)) {
                    $query->addSelect("agent.{$column}");
                }
            }

            $query->get()->each(function ($agent) use ($alerts) {
                    $translatedName = json_decode((string) $agent->name, true);
                    $label = is_array($translatedName)
                        ? (string) (reset($translatedName) ?: $agent->agent_id)
                        : ((string) $agent->name ?: ($agent->agent_id ?: "Print agent #{$agent->id}"));

                    if (! $agent->is_active || ! $this->seenRecently($agent->last_seen_at)) {
                    $alerts->push($this->alert('print_agent_offline', 'warning', 'Print agent offline', "{$label} is disabled or has missed recent heartbeats.", $agent->tenant_id, "print-agent:{$agent->id}:offline"));
                    }

                    $inventory = json_decode((string) ($agent->printer_inventory ?? '[]'), true) ?: [];
                    if ($agent->is_active && $this->seenRecently($agent->last_seen_at) && count($inventory) === 0) {
                        $alerts->push($this->alert('print_agent_unconfigured', 'warning', 'Print agent needs a printer', "{$label} is online but has not reported any printers.", $agent->tenant_id, "print-agent:{$agent->id}:unconfigured"));
                    }

                    $queue = json_decode((string) ($agent->queue_status ?? '{}'), true) ?: [];
                    $failureCount = (int) data_get($queue, 'failure_count', 0);
                    if ($failureCount > 0 || filled($agent->last_error ?? null)) {
                        $message = filled($agent->last_error ?? null)
                            ? "{$label}: {$agent->last_error}"
                            : "{$label} reports {$failureCount} failed print job(s).";
                        $alerts->push($this->alert('print_agent_failure', 'critical', 'Print agent failure', $message, $agent->tenant_id, "print-agent:{$agent->id}:failure"));
                    }
                });
        }

        return $alerts->sortByDesc(fn ($alert) => $alert['priority'])->take(8)->values();
    }

    private function rows(string $table): Collection
    {
        return Schema::hasTable($table) ? DB::table($table)->get() : collect();
    }

    private function recentRows(string $table, int $limit = 1000): Collection
    {
        if (! Schema::hasTable($table)) {
            return collect();
        }

        $query = DB::table($table);
        if (Schema::hasColumn($table, 'created_at')) {
            $query->latest('created_at');
        }

        return $query->limit($limit)->get();
    }

    private function count(string $table): int
    {
        return Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
    }

    private function countWhereIn(string $table, string $column, array $values): int
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column)
            ? (int) DB::table($table)->whereIn($column, $values)->count()
            : 0;
    }

    private function dateQuery(string $table, string $date): mixed
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, 'created_at')
            ? DB::table($table)->whereDate('created_at', $date)
            : null;
    }

    private function sumQuery(mixed $query, array $columns): float
    {
        if (! $query) {
            return 0.0;
        }

        $table = $query->from ?? null;
        $column = collect($columns)->first(fn ($column) => $table && Schema::hasColumn($table, $column));

        return $column ? (float) (clone $query)->sum($column) : 0.0;
    }

    private function databaseOk(): bool
    {
        return (bool) $this->safe(fn () => DB::select('select 1'), false);
    }

    private function queueStatus(): bool
    {
        if (! Schema::hasTable('jobs') || ! Schema::hasColumn('jobs', 'created_at')) return true;

        $oldest = DB::table('jobs')->min('created_at');

        return ! $oldest || Carbon::parse($oldest)->greaterThan(now()->subMinutes(5));
    }

    private function queueLabel(): string
    {
        $recentFailed = Schema::hasTable('failed_jobs') && Schema::hasColumn('failed_jobs', 'failed_at')
            ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count()
            : 0;
        $label = $this->count('jobs').' pending, '.$recentFailed.' failed in 24h';
        if (Schema::hasTable('jobs') && Schema::hasColumn('jobs', 'created_at')) {
            $oldest = DB::table('jobs')->min('created_at');
            if ($oldest) $label .= ', oldest '.Carbon::parse($oldest)->diffForHumans();
        }

        return $label;
    }

    private function redisStatus(): bool
    {
        return (bool) $this->safe(fn () => Redis::connection()->ping(), false);
    }

    private function reverbStatus(array $server): bool
    {
        if (! filled(data_get($server, 'processes.reverb.app_id'))) return false;

        $host = (string) (data_get($server, 'processes.reverb.host') ?: '127.0.0.1');
        if (in_array($host, ['0.0.0.0', '::'], true)) $host = '127.0.0.1';
        $port = (int) (data_get($server, 'processes.reverb.port') ?: 8080);
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 0.25);
        if (! $socket) return false;
        fclose($socket);

        return true;
    }

    private function reverbLabel(array $server): string
    {
        $host = data_get($server, 'processes.reverb.host') ?: '127.0.0.1';
        $port = data_get($server, 'processes.reverb.port') ?: 8080;

        return "Realtime endpoint {$host}:{$port}";
    }

    private function schedulerStatus(): bool
    {
        $heartbeat = $this->schedulerHeartbeat();

        return filled($heartbeat) && Carbon::parse($heartbeat)->greaterThan(now()->subMinutes(3));
    }

    private function schedulerLabel(): string
    {
        $heartbeat = $this->schedulerHeartbeat();

        return $heartbeat ? 'Last heartbeat '.Carbon::parse($heartbeat)->diffForHumans() : 'No scheduler heartbeat recorded';
    }

    private function schedulerHeartbeat(): ?string
    {
        $cached = $this->safe(fn () => Cache::get('saas:scheduler-heartbeat'), null);
        if (filled($cached)) return (string) $cached;
        if (! Storage::disk('local')->exists('saas/scheduler-heartbeat')) return null;

        return trim((string) Storage::disk('local')->get('saas/scheduler-heartbeat')) ?: null;
    }

    private function responseTimeMs(): int
    {
        $start = microtime(true);
        $this->safe(fn () => DB::select('select 1'), null);

        return (int) round((microtime(true) - $start) * 1000);
    }

    private function loadAverage(): array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];

        return ['one_minute' => round((float) ($load[0] ?? 0), 2), 'five_minutes' => round((float) ($load[1] ?? 0), 2)];
    }

    private function memoryUsage(): array
    {
        return ['php_mb' => round(memory_get_usage(true) / 1024 / 1024, 2), 'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)];
    }

    private function diskUsage(): array
    {
        $path = storage_path();
        $total = @disk_total_space($path) ?: 0;
        $free = @disk_free_space($path) ?: 0;

        return ['used_percent' => $total > 0 ? round((($total - $free) / $total) * 100, 2) : 0, 'free_gb' => round($free / 1024 / 1024 / 1024, 2)];
    }

    private function backupCount(): int
    {
        return collect(Storage::disk('local')->allFiles('tenants'))
            ->filter(fn (string $file) => str_contains($file, '/backups/') && str_ends_with($file, '.json'))
            ->count();
    }

    private function storageMb(string $path): float
    {
        if (! Storage::disk('local')->exists($path)) {
            return 0.0;
        }

        return round(collect(Storage::disk('local')->files($path))->sum(fn ($file) => Storage::disk('local')->size($file)) / 1024 / 1024, 2);
    }

    private function averagePrintTime(Collection $jobs): int
    {
        $durations = $jobs->filter(fn ($row) => filled($row->created_at ?? null) && filled($row->completed_at ?? null))
            ->map(fn ($row) => Carbon::parse($row->created_at)->diffInMilliseconds(Carbon::parse($row->completed_at)));

        return (int) round($durations->avg() ?? 0);
    }

    private function horizonSnapshot(): array
    {
        return $this->safe(function () {
            $redis = Redis::connection();
            $masters = collect($redis->smembers('horizon:masters') ?: []);
            $supervisors = $masters
                ->flatMap(fn ($master) => $redis->smembers("horizon:supervisors:{$master}") ?: [])
                ->values();
            $workloads = collect($redis->hgetall('horizon:workloads') ?: [])
                ->map(fn ($payload, $queue) => ['queue' => $queue, 'payload' => json_decode((string) $payload, true) ?: []])
                ->values();

            $recentJobs = collect($redis->zrevrange('horizon:recent_jobs', 0, 250) ?: [])
                ->map(fn ($id) => json_decode((string) $redis->get("horizon:{$id}"), true) ?: [])
                ->filter();

            $completed = $recentJobs->where('status', 'completed');
            $runtimeMs = $completed
                ->map(fn ($job) => (float) ($job['runtime'] ?? 0) * 1000)
                ->filter(fn ($runtime) => $runtime > 0);

            return [
                'available' => $masters->isNotEmpty() || $supervisors->isNotEmpty() || $workloads->isNotEmpty(),
                'masters' => $masters->count(),
                'supervisors' => $supervisors->count(),
                'workers_online' => (int) $workloads->sum(fn ($row) => (int) data_get($row, 'payload.processes', 0)),
                'workers_busy' => (int) $workloads->sum(fn ($row) => (int) data_get($row, 'payload.busyProcesses', 0)),
                'workers_idle' => max(0, (int) $workloads->sum(fn ($row) => (int) data_get($row, 'payload.processes', 0)) - (int) $workloads->sum(fn ($row) => (int) data_get($row, 'payload.busyProcesses', 0))),
                'completed_jobs' => $completed->count(),
                'average_processing_time_ms' => (int) round($runtimeMs->avg() ?? 0),
                'throughput_per_minute' => round($completed->filter(function ($job) {
                    $completedAt = $this->asCarbon($job['completed_at'] ?? null);

                    return $completedAt?->greaterThan(now()->subMinute()) ?? false;
                })->count(), 2),
                'worker_uptime_seconds' => 0,
                'workloads' => $workloads->map(fn ($row) => [
                    'queue' => $row['queue'],
                    'length' => (int) data_get($row, 'payload.length', 0),
                    'processes' => (int) data_get($row, 'payload.processes', 0),
                    'busy' => (int) data_get($row, 'payload.busyProcesses', 0),
                ])->all(),
            ];
        }, [
            'available' => false,
            'masters' => 0,
            'supervisors' => 0,
            'workers_online' => 0,
            'workers_busy' => 0,
            'workers_idle' => 0,
            'completed_jobs' => 0,
            'average_processing_time_ms' => 0,
            'throughput_per_minute' => 0,
            'worker_uptime_seconds' => 0,
            'workloads' => [],
        ]);
    }

    private function supervisorWorkerSnapshot(): array
    {
        return $this->safe(function (): array {
            $binary = (string) data_get(config('saas.server_automation'), 'processes.supervisorctl', '/usr/bin/supervisorctl');
            $workerProgram = trim((string) data_get(config('saas.server_automation'), 'processes.worker_program', ''));
            if (! is_file($binary) || ! is_executable($binary) || $workerProgram === '') {
                return ['available' => false, 'online' => 0];
            }

            $process = new Process([$binary, 'status']);
            $process->setTimeout(2);
            $process->run();
            if (! $process->isSuccessful()) {
                return ['available' => false, 'online' => 0];
            }

            $online = collect(preg_split('/\R/', trim($process->getOutput())) ?: [])
                ->filter(fn (string $line) => str_contains($line, $workerProgram) && preg_match('/\bRUNNING\b/', $line))
                ->count();

            return ['available' => true, 'online' => $online];
        }, ['available' => false, 'online' => 0]);
    }

    private function oldestWaitSeconds(Collection $rows): int
    {
        $oldest = $rows
            ->pluck('created_at')
            ->filter()
            ->map(fn ($date) => $this->asCarbon($date))
            ->filter()
            ->sort()
            ->first();

        return $oldest ? max(0, $oldest->diffInSeconds(now())) : 0;
    }

    private function durationLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            return floor($seconds / 60).'m '.($seconds % 60).'s';
        }

        return floor($seconds / 3600).'h '.floor(($seconds % 3600) / 60).'m';
    }

    private function asCarbon(mixed $value): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        return Carbon::parse($value);
    }

    private function seenRecently(mixed $date): bool
    {
        return filled($date) && Carbon::parse($date)->greaterThan(now()->subMinutes(3));
    }

    private function sameDate(mixed $date, string $target): bool
    {
        return filled($date) && Carbon::parse($date)->toDateString() === $target;
    }

    private function withinDays(mixed $date, int $days): bool
    {
        return filled($date) && Carbon::parse($date)->betweenIncluded(now(), now()->addDays($days));
    }

    private function rate(int|float $value, int|float $total): float
    {
        return round(($value / max(1, $total)) * 100, 2);
    }

    private function emptyStatus(): array
    {
        return ['total' => 0, 'online' => 0, 'offline' => 0, 'needs_update' => 0, 'inactive' => 0, 'battery_low' => 0, 'last_sync' => null];
    }

    private function safe(callable $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
