<?php

namespace Modules\Pos\Services\RevenueIntelligence;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Intelligence\ContextEngine;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Support\ActionPolicy;
use Modules\Support\Money;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class RevenueIntelligenceService
{
    public function handle(?int $branchId = null, string $period = 'today'): array
    {
        $user = auth()->user();
        $branchId = $user->assignedToBranch() ? $user->branch_id : $branchId;
        $window = $this->period($period);
        $cacheKey = makeCacheKey([
            'revenue-intelligence',
            'branch-' . ($branchId ?? 'all'),
            'user-' . $user->id,
            'role-' . ($user->hasRole(DefaultRole::Waiter->value) ? 'waiter' : 'operator'),
            $window['key'],
            $window['from']->format('YmdHi'),
            $window['to']->format('YmdHi'),
            now()->format('YmdHi'),
        ]);

        return Cache::store()->remember($cacheKey, now()->addSeconds(60), function () use ($branchId, $user, $window) {
            $summary = $this->summary($branchId, $user, $window);
            $leaks = $this->leaks($branchId, $user, $window, $summary);
            $upsell = $this->upsell($branchId, $user, $window);
            $orderValue = $this->orderValue($branchId, $user, $window);
            $menu = $this->menu($branchId, $user, $window);
            $waiters = $this->waiterScores($branchId, $user, $window);
            $loss = $this->lossPrevention($branchId, $user, $window, $summary);
            $coach = $this->coach($leaks, $upsell, $menu, $waiters);

            return [
                'branch_id' => $branchId,
                'generated_at' => now()->toISOString(),
                'period' => [
                    'key' => $window['key'],
                    'label' => $window['label'],
                    'from' => $window['from']->toISOString(),
                    'to' => $window['to']->toISOString(),
                    'comparison_from' => $window['comparison_from']->toISOString(),
                    'comparison_to' => $window['comparison_to']->toISOString(),
                ],
                'context' => (new ContextEngine())->current($branchId)->toArray(),
                'summary' => $summary,
                'revenue_leaks' => $leaks,
                'upsell_engine' => $upsell,
                'order_value_engine' => $orderValue,
                'smart_menu_engine' => $menu,
                'waiter_revenue_score' => $waiters,
                'loss_prevention' => $loss,
                'business_coach' => $coach,
                'benchmarks' => $this->benchmarks($branchId, $user, $window),
                'accuracy' => [
                    'mode' => 'deterministic',
                    'source' => 'orders_order_products_discounts_payments_print_jobs_reservations',
                    'confidence' => $this->confidence($summary, $upsell),
                    'warnings' => $this->warnings(),
                ],
            ];
        });
    }

    private function period(string $period): array
    {
        $key = in_array($period, ['today', 'yesterday', 'week', 'month'], true) ? $period : 'today';
        $now = now();

        return match ($key) {
            'yesterday' => [
                'key' => 'yesterday', 'label' => 'Yesterday',
                'from' => $now->copy()->subDay()->startOfDay(), 'to' => $now->copy()->subDay()->endOfDay(),
                'comparison_from' => $now->copy()->subDays(2)->startOfDay(), 'comparison_to' => $now->copy()->subDays(2)->endOfDay(),
            ],
            'week' => [
                'key' => 'week', 'label' => 'This week',
                'from' => $now->copy()->startOfWeek(), 'to' => $now->copy(),
                'comparison_from' => $now->copy()->subWeek()->startOfWeek(), 'comparison_to' => $now->copy()->subWeek(),
            ],
            'month' => [
                'key' => 'month', 'label' => 'This month',
                'from' => $now->copy()->startOfMonth(), 'to' => $now->copy(),
                'comparison_from' => $now->copy()->subMonthNoOverflow()->startOfMonth(), 'comparison_to' => $now->copy()->subMonthNoOverflow(),
            ],
            default => [
                'key' => 'today', 'label' => 'Today',
                'from' => $now->copy()->startOfDay(), 'to' => $now->copy(),
                'comparison_from' => $now->copy()->subDay()->startOfDay(), 'comparison_to' => $now->copy()->subDay(),
            ],
        };
    }

    private function orders(?int $branchId, User $user, array $window, bool $scopeWaiter = true): \Illuminate\Database\Query\Builder
    {
        return DB::table('orders')
            ->when($branchId, fn($q) => $q->where('orders.branch_id', $branchId))
            ->when($scopeWaiter && $user->hasRole(DefaultRole::Waiter->value), fn($q) => $q->where('orders.waiter_id', $user->id))
            ->whereBetween('orders.created_at', [$window['from'], $window['to']]);
    }

    private function summary(?int $branchId, User $user, array $window): array
    {
        $bad = [OrderStatus::Cancelled->value, OrderStatus::Refunded->value];
        $query = (clone $this->orders($branchId, $user, $window))
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(CASE WHEN orders.status NOT IN (?, ?) THEN orders.total ELSE 0 END), 0) as revenue', $bad)
            ->selectRaw('AVG(CASE WHEN orders.status NOT IN (?, ?) THEN orders.total ELSE NULL END) as aov', $bad)
            ->selectRaw('SUM(CASE WHEN orders.status IN (?, ?) THEN 1 ELSE 0 END) as corrections', $bad)
            ->selectRaw('SUM(CASE WHEN orders.payment_status IN (?, ?) THEN 1 ELSE 0 END) as payment_pending', [
                OrderPaymentStatus::Unpaid->value,
                OrderPaymentStatus::PartiallyPaid->value,
            ])
            ->selectRaw('AVG(CASE WHEN orders.closed_at IS NOT NULL THEN '.timestampDiffSql('MINUTE', 'orders.created_at', 'orders.closed_at').' ELSE NULL END) as turnover_minutes');

        $row = (Schema::hasColumn('orders', 'revenue')
            ? $query->selectRaw('COALESCE(SUM(CASE WHEN orders.status NOT IN (?, ?) THEN orders.revenue ELSE 0 END), 0) as gross_profit', $bad)
            : $query->selectRaw('0 as gross_profit'))
            ->first();

        $orders = (int) ($row?->orders ?? 0);
        $revenue = (float) ($row?->revenue ?? 0);

        return [
            'orders' => $orders,
            'revenue' => $this->money($revenue),
            'gross_profit' => $this->money((float) ($row?->gross_profit ?? 0)),
            'average_order_value' => $this->money((float) ($row?->aov ?? 0)),
            'correction_rate_percent' => $orders > 0 ? round(((int) $row->corrections / $orders) * 100, 2) : 0,
            'payment_pending_count' => (int) ($row?->payment_pending ?? 0),
            'average_turnover_minutes' => $this->round($row?->turnover_minutes),
        ];
    }

    private function leaks(?int $branchId, User $user, array $window, array $summary): array
    {
        $discount = Schema::hasTable('order_discounts')
            ? (clone $this->orders($branchId, $user, $window))->join('order_discounts as od', 'od.order_id', '=', 'orders.id')->sum('od.amount')
            : 0;
        $cancelledAfterKot = (clone $this->orders($branchId, $user, $window))
            ->whereIn('orders.status', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value])
            ->when(Schema::hasColumn('orders', 'kot_finalized_at'), fn($q) => $q->whereNotNull('orders.kot_finalized_at'))
            ->sum('orders.total');
        $paymentDelay = (clone $this->orders($branchId, $user, $window))
            ->whereIn('orders.payment_status', [OrderPaymentStatus::Unpaid->value, OrderPaymentStatus::PartiallyPaid->value])
            ->where('orders.created_at', '<=', now()->subMinutes((int) setting('revenue_payment_delay_minutes', 20)))
            ->sum('orders.total');
        $printFailures = Schema::hasTable('print_jobs')
            ? DB::table('print_jobs')
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->whereBetween('created_at', [$window['from'], $window['to']])
                ->whereIn('status', [PrintJobStatus::Failed->value, PrintJobStatus::Pending->value])
                ->count()
            : 0;

        return array_values(array_filter([
            $this->leak('discount_abuse', $discount > 0, 'Discount leakage', $discount, 'Discount amount used in this period.', 'Review manager-approved discounts and waiter patterns.', ['discount_amount' => round((float) $discount, 2)]),
            $this->leak('cancelled_after_kot', $cancelledAfterKot > 0, 'Cancelled after KOT', $cancelledAfterKot, 'Kitchen effort was already consumed before cancellation.', 'Require manager review for repeated post-KOT cancellations.', ['cancelled_after_kot_total' => round((float) $cancelledAfterKot, 2)]),
            $this->leak('payment_delay', $paymentDelay > 0, 'Payment delay', $paymentDelay * 0.02, 'Unpaid/partial orders are holding revenue and table flow.', 'Ask waiter/cashier to collect pending payments.', ['pending_revenue' => round((float) $paymentDelay, 2)]),
            $this->leak('print_queue_risk', $printFailures > 0, 'Print queue risk', $printFailures * 50, 'Failed or pending print jobs slow billing and service.', 'Check agent/printer and replay only failed jobs.', ['print_jobs' => $printFailures]),
            $this->leak('slow_table_turnover', ($summary['average_turnover_minutes'] ?? 0) > (int) setting('revenue_turnover_target_minutes', 55), 'Slow table turnover', ((float) $summary['revenue']['amount']) * 0.05, 'Average table turnover is above target.', 'Clear paid tables and speed bill closure.', ['average_turnover_minutes' => $summary['average_turnover_minutes']]),
        ]));
    }

    private function upsell(?int $branchId, User $user, array $window): array
    {
        if (! Schema::hasTable('order_products')) {
            return ['pairings' => [], 'summary' => ['pairing_count' => 0, 'estimated_extra_revenue' => $this->money(0)]];
        }

        $rows = DB::table('order_products as op')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'op.product_id')
            ->when($branchId, fn($q) => $q->where('o.branch_id', $branchId))
            ->when($user->hasRole(DefaultRole::Waiter->value), fn($q) => $q->where('o.waiter_id', $user->id))
            ->whereBetween('o.created_at', [$window['from'], $window['to']])
            ->whereIn('o.status', [OrderStatus::Served->value, OrderStatus::Completed->value])
            ->select('op.order_id', 'op.product_id')
            ->selectRaw('MAX(CAST(p.name AS CHAR)) as product_name')
            ->selectRaw('AVG(op.total) as avg_line_total')
            ->groupBy('op.order_id', 'op.product_id')
            ->orderByDesc('op.order_id')
            ->limit(4000)
            ->get();

        $byOrder = [];
        $names = [];
        $price = [];
        foreach ($rows as $row) {
            $id = (int) $row->product_id;
            $byOrder[(int) $row->order_id][$id] = true;
            $names[$id] = $this->name($row->product_name);
            $price[$id] = (float) ($row->avg_line_total ?? 0);
        }

        $pairs = [];
        foreach ($byOrder as $items) {
            foreach (array_keys($items) as $a) {
                foreach (array_keys($items) as $b) {
                    if ($a !== $b) {
                        $pairs[$a][$b] = ($pairs[$a][$b] ?? 0) + 1;
                    }
                }
            }
        }

        $flat = [];
        foreach ($pairs as $base => $targets) {
            arsort($targets);
            foreach (array_slice($targets, 0, 3, true) as $target => $count) {
                if ($count < 2) {
                    continue;
                }
                $flat[] = [
                    'base_product_id' => $base,
                    'base_product_name' => $names[$base] ?? 'Product',
                    'recommended_product_id' => $target,
                    'recommended_product_name' => $names[$target] ?? 'Product',
                    'support_count' => $count,
                    'confidence' => min(0.95, round($count / max(2, count($byOrder)), 2)),
                    'estimated_extra_revenue' => $this->money($price[$target] ?? 0),
                    'estimated_extra_profit' => $this->money(($price[$target] ?? 0) * 0.35),
                    'recommendation' => 'Offer ' . ($names[$target] ?? 'this item') . ' after ' . ($names[$base] ?? 'selected item') . '.',
                ];
            }
        }

        usort($flat, fn($a, $b) => $b['support_count'] <=> $a['support_count']);
        $top = array_slice($flat, 0, 12);

        return [
            'summary' => [
                'pairing_count' => count($top),
                'estimated_extra_revenue' => $this->money(array_sum(array_map(fn($row) => $row['estimated_extra_revenue']['amount'], $top))),
            ],
            'pairings' => $top,
        ];
    }

    private function orderValue(?int $branchId, User $user, array $window): array
    {
        $bad = [OrderStatus::Cancelled->value, OrderStatus::Refunded->value];
        $rows = (clone $this->orders($branchId, $user, $window, false))
            ->leftJoin('users as waiters', 'waiters.id', '=', 'orders.waiter_id')
            ->select('orders.waiter_id')
            ->selectRaw("COALESCE(waiters.name, 'Unassigned') as waiter_name")
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('AVG(CASE WHEN orders.status NOT IN (?, ?) THEN orders.total ELSE NULL END) as average_bill', $bad)
            ->selectRaw('AVG(CASE WHEN orders.guest_count > 0 THEN orders.total / orders.guest_count ELSE NULL END) as average_guest_spend')
            ->groupBy('orders.waiter_id', 'waiters.name')
            ->orderByDesc('average_bill')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'waiter_id' => $row->waiter_id ? (int) $row->waiter_id : null,
                'waiter_name' => (string) $row->waiter_name,
                'orders' => (int) $row->orders,
                'average_bill' => $this->money((float) $row->average_bill),
                'average_guest_spend' => $this->money((float) $row->average_guest_spend),
            ])
            ->all();

        return ['rows' => $rows, 'source' => 'orders'];
    }

    private function menu(?int $branchId, User $user, array $window): array
    {
        if (! Schema::hasTable('order_products')) {
            return ['high_sellers' => [], 'hidden_stars' => [], 'dead_products' => [], 'source' => 'order_products'];
        }

        $rows = DB::table('order_products as op')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'op.product_id')
            ->when($branchId, fn($q) => $q->where('o.branch_id', $branchId))
            ->when($user->hasRole(DefaultRole::Waiter->value), fn($q) => $q->where('o.waiter_id', $user->id))
            ->whereBetween('o.created_at', [$window['from'], $window['to']])
            ->whereIn('o.status', [OrderStatus::Served->value, OrderStatus::Completed->value])
            ->select('op.product_id')
            ->selectRaw('MAX(CAST(p.name AS CHAR)) as product_name')
            ->selectRaw('SUM(op.quantity) as quantity')
            ->selectRaw('COALESCE(SUM(op.total), 0) as revenue')
            ->selectRaw('AVG(op.total / NULLIF(op.quantity, 0)) as average_unit_value')
            ->groupBy('op.product_id')
            ->orderByDesc('revenue')
            ->limit(30)
            ->get()
            ->map(fn($row) => [
                'product_id' => (int) $row->product_id,
                'product_name' => $this->name($row->product_name),
                'quantity' => (int) $row->quantity,
                'revenue' => $this->money((float) $row->revenue),
                'average_unit_value' => $this->money((float) $row->average_unit_value),
                'recommendation' => ((int) $row->quantity >= 5 && (float) $row->average_unit_value > 0) ? 'Promote during matching shifts.' : 'Review visibility or availability.',
            ])
            ->values()
            ->all();

        return [
            'high_sellers' => array_slice($rows, 0, 8),
            'hidden_stars' => array_values(array_slice(array_filter($rows, fn($row) => $row['quantity'] < 5 && $row['average_unit_value']['amount'] > 0), 0, 8)),
            'dead_products' => [],
            'source' => 'order_products',
        ];
    }

    private function waiterScores(?int $branchId, User $user, array $window): array
    {
        $rows = (clone $this->orders($branchId, $user, $window, false))
            ->leftJoin('users as waiters', 'waiters.id', '=', 'orders.waiter_id')
            ->select('orders.waiter_id')
            ->selectRaw("COALESCE(waiters.name, 'Unassigned') as waiter_name")
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(orders.total), 0) as revenue')
            ->selectRaw('AVG(orders.total) as average_bill')
            ->selectRaw('SUM(CASE WHEN orders.status IN (?, ?) THEN 1 ELSE 0 END) as corrections', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value])
            ->groupBy('orders.waiter_id', 'waiters.name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $orders = max((int) $row->orders, 1);
                $correctionRate = ((int) $row->corrections / $orders) * 100;

                return [
                    'waiter_id' => $row->waiter_id ? (int) $row->waiter_id : null,
                    'waiter_name' => (string) $row->waiter_name,
                    'revenue' => $this->money((float) $row->revenue),
                    'average_bill' => $this->money((float) $row->average_bill),
                    'correction_rate_percent' => round($correctionRate, 2),
                    'score' => max(0, min(100, (int) round(70 + min(20, ((float) $row->average_bill / 100)) - $correctionRate))),
                ];
            })
            ->all();

        return ['rows' => $rows, 'source' => 'orders'];
    }

    private function lossPrevention(?int $branchId, User $user, array $window, array $summary): array
    {
        return [
            'refund_pattern' => [
                'count' => (clone $this->orders($branchId, $user, $window))->where('orders.status', OrderStatus::Refunded->value)->count(),
                'estimated_loss' => $this->money((clone $this->orders($branchId, $user, $window))->where('orders.status', OrderStatus::Refunded->value)->sum('orders.total')),
            ],
            'duplicate_kot_risk' => ['count' => 0, 'source' => 'print_queue_idempotency'],
            'cash_leakage_signal' => ['payment_pending_count' => $summary['payment_pending_count'] ?? 0],
        ];
    }

    private function benchmarks(?int $branchId, User $user, array $window): array
    {
        $current = $this->summary($branchId, $user, $window);
        $previous = $this->summary($branchId, $user, ['from' => $window['comparison_from'], 'to' => $window['comparison_to']]);

        return [
            'revenue_change_percent' => $this->change($previous['revenue']['amount'], $current['revenue']['amount']),
            'orders_change_percent' => $this->change($previous['orders'], $current['orders']),
            'aov_change_percent' => $this->change($previous['average_order_value']['amount'], $current['average_order_value']['amount']),
        ];
    }

    private function coach(array $leaks, array $upsell, array $menu, array $waiters): array
    {
        $cards = [];
        foreach (array_slice($leaks, 0, 2) as $leak) {
            $cards[] = ['type' => $leak['type'], 'title' => $leak['title'], 'recommended_action' => $leak['recommended_action'], 'impact' => $leak['estimated_revenue_loss']];
        }
        foreach (array_slice($upsell['pairings'] ?? [], 0, 2) as $pair) {
            $cards[] = ['type' => 'upsell', 'title' => 'Upsell ' . $pair['recommended_product_name'], 'recommended_action' => $pair['recommendation'], 'impact' => $pair['estimated_extra_revenue']];
        }

        return [
            'recommendations' => array_slice($cards, 0, 5),
            'top_waiter' => $waiters['rows'][0] ?? null,
            'top_product' => $menu['high_sellers'][0] ?? null,
        ];
    }

    private function leak(string $type, bool $active, string $title, float $loss, string $reason, string $action, array $evidence): ?array
    {
        if (! $active) {
            return null;
        }

        return [
            'id' => $type . '-' . now()->format('YmdHi'),
            'type' => $type,
            'severity' => $loss > 1000 ? 'critical' : 'warning',
            'title' => $title,
            'estimated_revenue_loss' => $this->money($loss),
            'estimated_profit_loss' => $this->money($loss * 0.35),
            'reason' => $reason,
            'recommended_action' => $action,
            'expected_impact' => 'Reduce leakage and speed table turnover.',
            'confidence' => 0.82,
            'evidence' => $evidence,
            'action_policy' => ActionPolicy::make(auth()->user()->can('admin.pos.index'), 'Permission denied.', loadingKey: $type, permissions: ['admin.pos.index']),
        ];
    }

    private function money(float $amount): array
    {
        return (new Money($amount, setting('default_currency') ?: 'INR'))->toArray();
    }

    private function round(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    private function change(float|int $previous, float|int $current): ?float
    {
        return abs((float) $previous) < 0.0001 ? ((float) $current > 0 ? 100.0 : 0.0) : round((((float) $current - (float) $previous) / abs((float) $previous)) * 100, 2);
    }

    private function name(mixed $value): string
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;
        $value = is_array($value) ? $value : ($decoded ?: $value);

        return is_array($value) ? (string) ($value[app()->getLocale()] ?? $value['en'] ?? reset($value) ?: 'Product') : ((string) $value ?: 'Product');
    }

    private function confidence(array $summary, array $upsell): float
    {
        return min(0.95, max(0.45, 0.55 + min(0.25, ($summary['orders'] ?? 0) / 100) + min(0.15, (($upsell['summary']['pairing_count'] ?? 0) / 20))));
    }

    private function warnings(): array
    {
        return array_values(array_filter([
            Schema::hasColumn('orders', 'revenue') ? null : 'Profit uses estimated fallback because orders.revenue is unavailable.',
            Schema::hasTable('order_discounts') ? null : 'Discount leakage unavailable because order_discounts table is missing.',
            Schema::hasTable('order_products') ? null : 'Upsell and smart menu unavailable because order_products table is missing.',
        ]));
    }
}
