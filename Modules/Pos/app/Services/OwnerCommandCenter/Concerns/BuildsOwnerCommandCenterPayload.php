<?php

namespace Modules\Pos\Services\OwnerCommandCenter\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Enums\OrderStatus;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\Support\ActionPolicy;
use Modules\Support\Money;

trait BuildsOwnerCommandCenterPayload
{
    private function topWaiter(?int $branchId): ?string
    {
        if (! Schema::hasTable('orders')) return null;
        $row = DB::table('orders')->leftJoin('users', 'users.id', '=', 'orders.waiter_id')
            ->when($branchId, fn($q) => $q->where('orders.branch_id', $branchId))
            ->whereDate('orders.created_at', today())->whereNotIn('orders.status', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value])
            ->groupBy('orders.waiter_id', 'users.name')->selectRaw("COALESCE(users.name, 'Unassigned') as name")->selectRaw('COALESCE(SUM(orders.total), 0) as total')
            ->orderByDesc('total')->first();
        return $row ? $row->name . ' · ' . $this->money((float) $row->total)['formatted'] : null;
    }

    private function topTable(?int $branchId): ?string
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('tables')) return null;
        $row = DB::table('orders')->join('tables', 'tables.id', '=', 'orders.table_id')
            ->when($branchId, fn($q) => $q->where('orders.branch_id', $branchId))
            ->whereDate('orders.created_at', today())->whereNotIn('orders.status', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value])
            ->groupBy('orders.table_id', 'tables.name')->selectRaw('CAST(tables.name AS CHAR) as name')->selectRaw('COALESCE(SUM(orders.total), 0) as total')
            ->orderByDesc('total')->first();
        return $row ? $this->name($row->name) . ' · ' . $this->money((float) $row->total)['formatted'] : null;
    }

    private function topProduct(?int $branchId): ?string
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('order_products')) return null;
        $row = DB::table('order_products as op')->join('orders as o', 'o.id', '=', 'op.order_id')->leftJoin('products as p', 'p.id', '=', 'op.product_id')
            ->when($branchId, fn($q) => $q->where('o.branch_id', $branchId))
            ->whereDate('o.created_at', today())->groupBy('op.product_id', 'p.name')->selectRaw('CAST(p.name AS CHAR) as name')->selectRaw('SUM(op.quantity) as qty')
            ->orderByDesc('qty')->first();
        return $row ? $this->name($row->name) . ' · ' . (int) $row->qty : null;
    }

    private function actionFromAutomation(array $item): ?array
    {
        $action = $item['action'] ?? [];
        return $this->quickAction(
            $item['id'] ?? uniqid('automation-', false),
            $action['label'] ?? ($item['title'] ?? 'Review action'),
            $item['recommended_action'] ?? ($item['reason'] ?? null),
            $this->routeForAction($action['key'] ?? null),
            $this->firstPermission($item['permissions'] ?? []),
            $action['key'] ?? 'review',
            $item['action_policy'] ?? null,
            $item['severity'] ?? 'info',
        );
    }

    private function actionFromCard(array $card): ?array
    {
        $action = $card['action'] ?? [];
        $actionKey = is_array($action) ? ($action['key'] ?? 'open') : (string) ($action ?: 'open');
        $actionLabel = is_array($action) ? ($action['label'] ?? ($card['title'] ?? 'Open')) : ($card['title'] ?? 'Open');

        return $this->quickAction(
            $card['id'] ?? uniqid('assistant-', false),
            $actionLabel,
            $card['message'] ?? null,
            $this->routeForAction($actionKey),
            null,
            $actionKey,
            $card['action_policy'] ?? null,
            $card['severity'] ?? 'info',
        );
    }

    private function quickAction(string $id, string $title, ?string $message, ?string $route, ?string $permission, string $key, ?array $policy = null, string $severity = 'info'): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'message' => $message,
            'severity' => $severity,
            'action' => ['key' => $key, 'route_name' => $route, 'loading_key' => $key],
            'action_policy' => $policy ?? $this->policy($permission, $key),
        ];
    }

    private function summaryItem(array $item, string $type): ?array
    {
        $title = $item['title'] ?? $item['name'] ?? $item['base_product_name'] ?? $item['reason'] ?? null;
        if (! $title) return null;
        $confidence = (float) ($item['confidence'] ?? 0);
        return [
            'id' => $item['id'] ?? md5($type . $title),
            'type' => $type,
            'severity' => $item['severity'] ?? ($confidence > 0.7 ? 'warning' : 'info'),
            'title' => (string) $title,
            'message' => $item['message'] ?? $item['impact'] ?? $item['recommendation'] ?? $item['recommended_action'] ?? null,
            'business_impact' => $item['estimated_extra_revenue'] ?? $item['estimated_loss'] ?? null,
        ];
    }

    private function metricCard(string $id, string $label, mixed $value, string $icon): ?array
    {
        return $value === null ? null : ['id' => $id, 'label' => $label, 'value' => $value, 'icon' => $icon];
    }

    private function policy(?string $permission, string $loadingKey): array
    {
        $allowed = ! $permission || auth()->user()->can($permission);
        return ActionPolicy::make($allowed, $allowed ? null : 'Permission denied.', true, false, $loadingKey, $permission ? [$permission] : []);
    }

    private function routeForAction(?string $action): ?string
    {
        return [
            'open_table' => 'admin.tables.index',
            'mark_available' => 'admin.tables.index',
            'assign_waiter' => 'admin.tables.index',
            'serve_ready_order' => 'admin.pos.kitchen_viewer',
            'notify_waiter' => 'admin.pos.kitchen_viewer',
            'notify_kitchen' => 'admin.pos.kitchen_viewer',
            'collect_payment' => 'admin.orders.index',
            'open_order' => 'admin.orders.index',
            'retry_print' => 'admin.print_jobs.index',
            'retry_print_queue' => 'admin.print_jobs.index',
            'balance_print_queue' => 'admin.print_jobs.index',
            'release_reservation' => 'admin.reservations.index',
            'open_reservation' => 'admin.reservations.index',
            'open_pos_session' => 'admin.pos_sessions.index',
            'manager_approval' => 'admin.pos.manager_approvals',
            'owner_morning_summary' => 'admin.pos.owner_command_center',
        ][$action] ?? null;
    }

    private function firstPermission(array $permissions): ?string
    {
        return is_string($permissions[0] ?? null) ? $permissions[0] : null;
    }

    private function heatReason(string $severity, object $row): string
    {
        if ($row->payment_pending > 0) return 'payment_pending';
        if ($row->ready_orders > 0) return 'kitchen_ready';
        if ($severity === 'critical' || $severity === 'warning') return 'waiting_time';
        return $row->status === TableStatus::Available->value ? 'available' : 'normal';
    }

    private function money(float|int $amount): array
    {
        return Money::inDefaultCurrency(round((float) $amount, 2))->toArray();
    }

    private function name(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return (string) ($decoded[app()->getLocale()] ?? $decoded['en'] ?? reset($decoded));
            }
        }
        return (string) $value;
    }

    private function safe(callable $callback, array $default): array
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return $default;
        }
    }
}
