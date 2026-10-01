<?php

namespace Modules\Pos\Services\OwnerCommandCenter;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Pos\Services\Automation\RestaurantAutomationService;
use Modules\Pos\Services\OwnerCommandCenter\Concerns\BuildsOwnerCommandCenterPayload;
use Modules\Pos\Services\PosViewer\PosViewerServiceInterface;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\SeatingPlan\Enums\ReservationStatus;

class OwnerCommandCenterService
{
    use BuildsOwnerCommandCenterPayload;

    public function __construct(
        private readonly PosViewerServiceInterface $viewer,
        private readonly RestaurantAutomationService $automation,
    ) {
    }

    public function show(?int $branchId = null): array
    {
        $user = auth()->user();
        $branchId = $user->assignedToBranch() ? $user->branch_id : $branchId;
        $cacheKey = makeCacheKey([
            'owner-command-center',
            'branch-' . ($branchId ?? 'all'),
            'user-' . $user->id,
            now()->format('YmdHi'),
        ]);

        return Cache::store()->remember($cacheKey, now()->addSeconds(10), function () use ($branchId) {
            $assistant = $this->safe(fn() => $this->viewer->waiterAssistant($branchId), []);
            $performance = $this->safe(fn() => $this->viewer->performanceIntelligence($branchId), []);
            $revenue = $this->safe(fn() => $this->viewer->revenueIntelligence($branchId), []);
            $automation = $this->safe(fn() => $this->automation->evaluate($branchId), []);
            $live = $this->liveSnapshot($branchId, $automation);
            $heatmap = $this->floorHeatmap($branchId);
            $summary = $this->executiveSummary($assistant, $performance, $revenue, $automation, $live);

            return [
                'branch_id' => $branchId,
                'generated_at' => now()->toISOString(),
                'health' => $this->health($live, $summary, $performance),
                'live' => $live,
                'heatmap' => $heatmap,
                'executive_summary' => $summary,
                'actions' => $this->actionCenter($assistant, $automation, $live),
                'timeline' => $this->timeline($performance, $branchId),
                'performance_cards' => $this->performanceCards($branchId, $performance, $revenue),
                'business_coach' => $this->businessCoach($revenue, $automation, $summary),
                'sources' => [
                    'assistant' => ! empty($assistant),
                    'performance_intelligence' => ! empty($performance),
                    'revenue_intelligence' => ! empty($revenue),
                    'automation' => ! empty($automation),
                ],
            ];
        });
    }

    private function liveSnapshot(?int $branchId, array $automation): array
    {
        $orders = $this->orderLiveCounts($branchId);
        $printer = $this->printerCounts($branchId);
        $terminal = $this->terminalCounts($branchId);
        $reservations = $this->reservationCounts($branchId);
        $autoQueue = collect($automation['decisions'] ?? [])->where('state', '!=', 'inactive')->count();

        return [
            'revenue_today' => $this->money($orders['revenue_today']),
            'orders_running' => $orders['running'],
            'kitchen_load' => [
                'preparing' => $orders['preparing'],
                'ready' => $orders['ready'],
                'delayed' => $orders['delayed'],
            ],
            'waiter_load' => $this->waiterLoad($branchId),
            'printer_health' => $printer,
            'realtime_status' => [
                'status' => $terminal['offline'] > 0 ? 'warning' : 'ok',
                'online_terminals' => $terminal['online'],
                'offline_terminals' => $terminal['offline'],
                'last_seen_at' => $terminal['last_seen_at'],
            ],
            'offline_queue' => [
                'status' => $terminal['queue_count'] > 0 ? 'warning' : 'ok',
                'count' => $terminal['queue_count'],
            ],
            'payment_queue' => [
                'count' => $orders['payment_pending'],
                'amount' => $this->money($orders['payment_pending_amount']),
            ],
            'reservation_queue' => $reservations,
            'automation_queue' => [
                'count' => $autoQueue,
                'critical' => collect($automation['decisions'] ?? [])->where('severity', 'critical')->where('state', '!=', 'inactive')->count(),
            ],
        ];
    }

    private function orderLiveCounts(?int $branchId): array
    {
        if (! Schema::hasTable('orders')) {
            return array_fill_keys(['revenue_today', 'running', 'preparing', 'ready', 'delayed', 'payment_pending', 'payment_pending_amount'], 0);
        }

        $running = [OrderStatus::Pending->value, OrderStatus::Confirmed->value, OrderStatus::Preparing->value, OrderStatus::Ready->value, OrderStatus::Served->value];
        $bad = [OrderStatus::Cancelled->value, OrderStatus::Refunded->value];
        $paymentPending = [OrderPaymentStatus::Unpaid->value, OrderPaymentStatus::PartiallyPaid->value];
        $delayMinutes = max((int) setting('occ_kitchen_delay_minutes', 18), 5);

        $row = DB::table('orders')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->whereDate('created_at', today())
            ->selectRaw('COALESCE(SUM(CASE WHEN status NOT IN (?, ?) THEN total ELSE 0 END), 0) as revenue_today', $bad)
            ->selectRaw('SUM(CASE WHEN status IN (?, ?, ?, ?, ?) THEN 1 ELSE 0 END) as running', $running)
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as preparing', [OrderStatus::Preparing->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as ready', [OrderStatus::Ready->value])
            ->selectRaw('SUM(CASE WHEN status = ? AND updated_at <= ? THEN 1 ELSE 0 END) as delayed_orders', [OrderStatus::Preparing->value, now()->subMinutes($delayMinutes)])
            ->selectRaw('SUM(CASE WHEN payment_status IN (?, ?) AND status NOT IN (?, ?) THEN 1 ELSE 0 END) as payment_pending', [...$paymentPending, ...$bad])
            ->selectRaw('COALESCE(SUM(CASE WHEN payment_status IN (?, ?) AND status NOT IN (?, ?) THEN total ELSE 0 END), 0) as payment_pending_amount', [...$paymentPending, ...$bad])
            ->first();

        return [
            'revenue_today' => (float) ($row?->revenue_today ?? 0),
            'running' => (int) ($row?->running ?? 0),
            'preparing' => (int) ($row?->preparing ?? 0),
            'ready' => (int) ($row?->ready ?? 0),
            'delayed' => (int) ($row?->delayed_orders ?? 0),
            'payment_pending' => (int) ($row?->payment_pending ?? 0),
            'payment_pending_amount' => (float) ($row?->payment_pending_amount ?? 0),
        ];
    }

    private function waiterLoad(?int $branchId): array
    {
        if (! Schema::hasTable('orders')) {
            return ['active_waiters' => 0, 'max_running_orders' => 0, 'top_waiter' => null];
        }

        $rows = DB::table('orders')
            ->leftJoin('users', 'users.id', '=', 'orders.waiter_id')
            ->when($branchId, fn($q) => $q->where('orders.branch_id', $branchId))
            ->whereDate('orders.created_at', today())
            ->whereIn('orders.status', [OrderStatus::Pending->value, OrderStatus::Confirmed->value, OrderStatus::Preparing->value, OrderStatus::Ready->value, OrderStatus::Served->value])
            ->groupBy('orders.waiter_id', 'users.name')
            ->select('orders.waiter_id')
            ->selectRaw("COALESCE(users.name, 'Unassigned') as waiter_name")
            ->selectRaw('COUNT(*) as running_orders')
            ->orderByDesc('running_orders')
            ->limit(5)
            ->get();

        return [
            'active_waiters' => $rows->count(),
            'max_running_orders' => (int) ($rows->first()?->running_orders ?? 0),
            'top_waiter' => $rows->first() ? [
                'id' => $rows->first()->waiter_id,
                'name' => $rows->first()->waiter_name,
                'running_orders' => (int) $rows->first()->running_orders,
            ] : null,
        ];
    }

    private function printerCounts(?int $branchId): array
    {
        if (! Schema::hasTable('print_jobs')) {
            return ['status' => 'unknown', 'pending' => 0, 'failed' => 0];
        }

        $pending = DB::table('print_jobs')->when($branchId, fn($q) => $q->where('branch_id', $branchId))->where('status', PrintJobStatus::Pending->value)->count();
        $failed = DB::table('print_jobs')->when($branchId, fn($q) => $q->where('branch_id', $branchId))->where('status', PrintJobStatus::Failed->value)->where('created_at', '>=', now()->subHours(2))->count();

        return ['status' => $failed > 0 ? 'critical' : ($pending > 0 ? 'warning' : 'ok'), 'pending' => $pending, 'failed' => $failed];
    }

    private function terminalCounts(?int $branchId): array
    {
        if (! Schema::hasTable('pos_terminal_devices')) {
            return ['online' => 0, 'offline' => 0, 'queue_count' => 0, 'last_seen_at' => null];
        }

        $devices = DB::table('pos_terminal_devices')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->select('status', 'local_queue_count', 'server_queue_count', 'last_seen_at')
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();

        return [
            'online' => $devices->whereIn('status', ['online', 'syncing'])->count(),
            'offline' => $devices->whereIn('status', ['offline', 'error'])->count(),
            'queue_count' => (int) $devices->sum(fn($row) => (int) $row->local_queue_count + (int) $row->server_queue_count),
            'last_seen_at' => $devices->first()?->last_seen_at,
        ];
    }

    private function reservationCounts(?int $branchId): array
    {
        if (! Schema::hasTable('table_reservations')) {
            return ['count' => 0, 'arriving_soon' => 0];
        }

        $query = DB::table('table_reservations')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->whereDate('reservation_date', today())
            ->whereIn('status', [ReservationStatus::Pending->value, ReservationStatus::Confirmed->value]);

        return [
            'count' => (clone $query)->count(),
            'arriving_soon' => (clone $query)
                ->where('reservation_time', '>=', now()->format('H:i:s'))
                ->where('reservation_time', '<=', now()->addMinutes(45)->format('H:i:s'))
                ->count(),
        ];
    }

    private function floorHeatmap(?int $branchId): array
    {
        if (! Schema::hasTable('tables')) {
            return [];
        }

        $orders = DB::table('orders')
            ->select('table_id')
            ->selectRaw('COUNT(*) as running_orders')
            ->selectRaw('COALESCE(SUM(total), 0) as revenue')
            ->selectRaw('MIN(created_at) as oldest_order_at')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as ready_orders', [OrderStatus::Ready->value])
            ->selectRaw('SUM(CASE WHEN payment_status IN (?, ?) THEN 1 ELSE 0 END) as payment_pending', [OrderPaymentStatus::Unpaid->value, OrderPaymentStatus::PartiallyPaid->value])
            ->whereNotNull('table_id')
            ->whereDate('created_at', today())
            ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Confirmed->value, OrderStatus::Preparing->value, OrderStatus::Ready->value, OrderStatus::Served->value])
            ->groupBy('table_id');

        $query = DB::table('tables')
            ->leftJoinSub($orders, 'live_orders', 'live_orders.table_id', '=', 'tables.id')
            ->when($branchId, fn($q) => $q->where('tables.branch_id', $branchId));

        if (Schema::hasTable('floors')) {
            $query->leftJoin('floors', 'floors.id', '=', 'tables.floor_id');
        }
        if (Schema::hasTable('zones')) {
            $query->leftJoin('zones', 'zones.id', '=', 'tables.zone_id');
        }

        return $query
            ->select('tables.id', 'tables.name', 'tables.status', 'tables.capacity', 'tables.updated_at')
            ->selectRaw('COALESCE(live_orders.running_orders, 0) as running_orders')
            ->selectRaw('COALESCE(live_orders.revenue, 0) as revenue')
            ->selectRaw('live_orders.oldest_order_at')
            ->selectRaw('COALESCE(live_orders.ready_orders, 0) as ready_orders')
            ->selectRaw('COALESCE(live_orders.payment_pending, 0) as payment_pending')
            ->selectRaw(Schema::hasTable('floors') ? 'CAST(floors.name AS CHAR) as floor_name' : 'NULL as floor_name')
            ->selectRaw(Schema::hasTable('zones') ? 'CAST(zones.name AS CHAR) as zone_name' : 'NULL as zone_name')
            ->when(
                Schema::hasColumn('tables', 'order'),
                fn($q) => $q->orderBy('tables.order'),
                fn($q) => $q->orderBy('tables.id'),
            )
            ->limit(250)
            ->get()
            ->map(fn($row) => $this->heatmapRow($row))
            ->all();
    }

    private function heatmapRow(object $row): array
    {
        $waiting = $row->oldest_order_at
            ? now()->diffInMinutes(\Illuminate\Support\Carbon::parse($row->oldest_order_at))
            : 0;
        $severity = $row->payment_pending > 0 ? 'warning' : ($row->ready_orders > 0 || $waiting >= 45 ? 'critical' : ($waiting >= 25 ? 'warning' : 'normal'));

        return [
            'table_id' => (int) $row->id,
            'name' => $this->name($row->name),
            'floor' => $this->name($row->floor_name),
            'zone' => $this->name($row->zone_name),
            'status' => $row->status,
            'capacity' => (int) $row->capacity,
            'running_orders' => (int) $row->running_orders,
            'revenue' => $this->money((float) $row->revenue),
            'waiting_minutes' => $waiting,
            'ready_orders' => (int) $row->ready_orders,
            'payment_pending' => (int) $row->payment_pending,
            'heat' => $severity,
            'heat_reason' => $this->heatReason($severity, $row),
            'action_policy' => $this->policy('admin.tables.viewer', 'open_table'),
        ];
    }

    private function executiveSummary(array $assistant, array $performance, array $revenue, array $automation, array $live): array
    {
        $problems = collect($assistant['cards'] ?? [])
            ->merge($performance['bottlenecks'] ?? [])
            ->filter(fn($item) => is_array($item))
            ->map(fn($item) => $this->summaryItem($item, 'problem'));
        $risks = collect($automation['decisions'] ?? [])
            ->filter(fn($item) => is_array($item))
            ->where('state', '!=', 'inactive')
            ->map(fn($item) => $this->summaryItem($item, 'risk'));
        $opportunities = collect($revenue['upsell_engine']['pairings'] ?? [])
            ->filter(fn($item) => is_array($item))
            ->map(fn($item) => $this->summaryItem($item, 'opportunity'));
        $recommendations = collect($performance['recommendations'] ?? [])
            ->merge($revenue['business_coach'] ?? [])
            ->filter(fn($item) => is_array($item))
            ->map(fn($item) => $this->summaryItem($item, 'recommendation'));

        if (($live['payment_queue']['count'] ?? 0) > 0) {
            $problems->push($this->summaryItem(['title' => 'Payment queue', 'message' => 'Orders are waiting for collection.', 'severity' => 'warning'], 'problem'));
        }

        return [
            'problems' => $problems->filter()->take(5)->values()->all(),
            'opportunities' => $opportunities->filter()->take(5)->values()->all(),
            'risks' => $risks->filter()->take(5)->values()->all(),
            'recommendations' => $recommendations->filter()->take(5)->values()->all(),
        ];
    }

    private function actionCenter(array $assistant, array $automation, array $live): array
    {
        $actions = collect($automation['decisions'] ?? [])
            ->filter(fn($item) => is_array($item))
            ->where('state', '!=', 'inactive')
            ->map(fn($item) => $this->actionFromAutomation($item));

        $assistantActions = collect($assistant['cards'] ?? [])
            ->filter(fn($card) => is_array($card))
            ->filter(fn($card) => ($card['action_policy']['visible'] ?? true))
            ->map(fn($card) => $this->actionFromCard($card));

        if (($live['printer_health']['failed'] ?? 0) > 0) {
            $actions->push($this->quickAction('retry-print', 'Retry failed prints', 'Printer failures need attention.', 'admin.print_jobs.index', 'admin.print_jobs.retry', 'retry_print'));
        }
        if (($live['payment_queue']['count'] ?? 0) > 0) {
            $actions->push($this->quickAction('collect-payment', 'Collect pending payments', 'Open active orders with unpaid balances.', 'admin.orders.index', 'admin.orders.receive_payment', 'collect_payment'));
        }

        return $actions->merge($assistantActions)->filter()->take(12)->values()->all();
    }

    private function timeline(array $performance, ?int $branchId): array
    {
        $items = collect($performance['operation_timeline'] ?? [])->filter(fn($item) => is_array($item))->map(fn($item) => [
            'id' => $item['id'] ?? uniqid('timeline-', false),
            'type' => $item['type'] ?? 'operation',
            'title' => $item['title'] ?? 'Operation event',
            'message' => $item['message'] ?? ($item['detail'] ?? null),
            'severity' => $item['severity'] ?? 'info',
            'created_at' => $item['created_at'] ?? now()->toISOString(),
        ]);

        if (Schema::hasTable('automation_executions')) {
            DB::table('automation_executions')
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->latest()
                ->limit(8)
                ->get(['id', 'automation_id', 'state', 'action_key', 'created_at'])
                ->each(fn($row) => $items->push([
                    'id' => 'automation-'.$row->id,
                    'type' => 'automation',
                    'title' => $row->automation_id,
                    'message' => $row->action_key,
                    'severity' => $row->state === 'failed' ? 'critical' : 'info',
                    'created_at' => $row->created_at,
                ]));
        }

        return $items->sortByDesc('created_at')->take(20)->values()->all();
    }

    private function performanceCards(?int $branchId, array $performance, array $revenue): array
    {
        return array_values(array_filter([
            $this->metricCard('top_waiter', 'Top Waiter', $this->topWaiter($branchId), 'tabler-user-star'),
            $this->metricCard('top_table', 'Top Table', $this->topTable($branchId), 'tabler-armchair-2'),
            $this->metricCard('top_product', 'Top Product', $this->topProduct($branchId), 'tabler-bowl'),
            $this->metricCard('kitchen_delay', 'Kitchen Delay', $performance['kitchen_performance']['summary']['delayed_orders'] ?? null, 'tabler-chef-hat'),
            $this->metricCard('average_order_value', 'Average Order Value', $revenue['summary']['average_order_value']['formatted'] ?? null, 'tabler-receipt-rupee'),
        ]));
    }

    private function businessCoach(array $revenue, array $automation, array $summary): array
    {
        return collect($revenue['business_coach'] ?? [])
            ->merge($automation['playbooks'] ?? [])
            ->merge($summary['recommendations'] ?? [])
            ->filter(fn($item) => is_array($item))
            ->map(fn($item) => $this->summaryItem($item, 'coach'))
            ->filter()
            ->take(8)
            ->values()
            ->all();
    }

    private function health(array $live, array $summary, array $performance): array
    {
        $critical = collect($summary['problems'])->where('severity', 'critical')->count() + (int) ($live['automation_queue']['critical'] ?? 0);
        $warning = collect($summary['problems'])->where('severity', 'warning')->count()
            + (int) (($live['printer_health']['pending'] ?? 0) > 0);
        $score = (int) max(0, min(100, ($performance['restaurant_health']['score'] ?? 100) - ($critical * 15) - ($warning * 6)));

        return [
            'score' => $score,
            'level' => $score >= 85 ? 'healthy' : ($score >= 65 ? 'watch' : 'critical'),
            'critical_count' => $critical,
            'warning_count' => $warning,
        ];
    }

}
