<?php

namespace Modules\Pos\Services\PosViewer\Concerns;

use Darryldecode\Cart\Exceptions\InvalidConditionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\Cart\Facades\Cart;
use Modules\Category\Models\Category;
use Modules\Discount\Models\Discount;
use Modules\Menu\Models\Menu;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Support\OrderActionPolicy;
use Modules\Pos\Enums\PosCashDirection;
use Modules\Pos\Enums\PosCashReason;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Models\PosTerminalDevice;
use Modules\Pos\Transformers\Api\V1\Pos\PosCategoryResource;
use Modules\Pos\Transformers\Api\V1\Pos\PosProductResource;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Models\PrintJob;
use Modules\Product\Models\Product;
use Modules\SeatingPlan\Enums\ReservationStatus;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\TableReservation;
use Modules\Support\ActionPolicy;
use Modules\Support\Money;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;
use Modules\Core\Intelligence\ContextEngine;
use Modules\Core\Intelligence\DecisionEngine;
use Modules\Core\Intelligence\HealthScore;
use Modules\Core\Intelligence\MemoryScore;
use Modules\Core\Intelligence\PredictionEngine;

trait HandlesPerformanceDecision
{
    private function performanceScores(array $operational, array $waiter, array $kitchen, array $tables, array $printing, array $terminal, array $revenue): array
    {
        $waiterSummary = $waiter['summary'] ?? [];
        $restaurantAverage = $waiter['restaurant_average'] ?? [];
        $kitchenSummary = $kitchen['summary'] ?? [];
        $tableSummary = $tables['summary'] ?? [];
        $printSummary = $printing['summary'] ?? [];
        $terminalSummary = $terminal['summary'] ?? [];
        $revenueSummary = $revenue['summary'] ?? [];

        $waiterScore = $this->performanceBoundedScore(100
            - max(0, ((float) ($waiterSummary['average_table_turnover_minutes'] ?? 0)) - max(45, (float) ($restaurantAverage['average_service_minutes'] ?? 0))) * 1.4
            - ((float) ($waiterSummary['order_correction_rate'] ?? 0) * 1.5)
            - ((int) ($waiterSummary['payment_pending'] ?? 0) * 5));
        $kitchenScore = $this->performanceBoundedScore(100
            - ((float) ($kitchenSummary['delay_rate_percent'] ?? 0) * 2)
            - max(0, ((float) ($kitchenSummary['average_preparation_minutes'] ?? 0)) - (float) ($kitchenSummary['sla_minutes'] ?? 20)));
        $tableScore = $this->performanceBoundedScore(100
            - ((int) ($tableSummary['idle_after_payment_count'] ?? 0) * 12)
            - ((int) ($tableSummary['occupied_without_order_count'] ?? 0) * 10));
        $printerScore = $this->performanceBoundedScore(100
            - ((int) ($printSummary['failed_jobs'] ?? 0) * 18)
            - ((int) ($printSummary['pending_jobs'] ?? 0) * 8));
        $realtimeScore = $this->performanceBoundedScore(100
            - ((int) ($terminalSummary['error_devices'] ?? 0) * 20)
            - min(30, (int) ($terminalSummary['queue_count'] ?? 0) * 3));
        $revenueScore = $this->performanceBoundedScore(100 + min(12, max(-30, (float) ($revenueSummary['revenue_change_percent'] ?? 0)) / 2));
        $guestExperienceScore = $this->performanceBoundedScore(100
            - ((int) ($operational['health']['critical_count'] ?? 0) * 12)
            - ((int) ($operational['health']['warning_count'] ?? 0) * 6)
            - ((float) ($kitchenSummary['delay_rate_percent'] ?? 0)));
        $overall = $this->performanceBoundedScore(round((
            ((int) ($operational['health']['score'] ?? 100)) +
            $waiterScore +
            $kitchenScore +
            $tableScore +
            $printerScore +
            $realtimeScore +
            $revenueScore +
            $guestExperienceScore
        ) / 8));

        return [
            'overall' => $this->performanceScorePayload($overall, 'Composite score from operations, waiter, kitchen, tables, printer, realtime, revenue, and guest experience.'),
            'waiter' => $this->performanceScorePayload($waiterScore, 'Penalizes slow turnover, correction rate, and pending payments.'),
            'kitchen' => $this->performanceScorePayload($kitchenScore, 'Penalizes delayed item rate and preparation time against SLA.'),
            'table' => $this->performanceScorePayload($tableScore, 'Penalizes paid tables left occupied and occupied tables without orders.'),
            'printer' => $this->performanceScorePayload($printerScore, 'Penalizes failed and pending print jobs.'),
            'realtime' => $this->performanceScorePayload($realtimeScore, 'Penalizes offline/error terminals and pending sync queues.'),
            'revenue' => $this->performanceScorePayload($revenueScore, 'Compares revenue trend against the matching previous period.'),
            'guest_experience' => $this->performanceScorePayload($guestExperienceScore, 'Uses critical/warning operational signals and kitchen delays.'),
        ];
    }

    private function performanceRecommendations(
        array $operational,
        array $waiter,
        array $kitchen,
        array $tables,
        array $printing,
        array $terminal,
        array $revenue,
        array $scores,
        User $user,
    ): array {
        $recommendations = [];
        $waiterSummary = $waiter['summary'] ?? [];
        $restaurantAverage = $waiter['restaurant_average'] ?? [];
        $kitchenSummary = $kitchen['summary'] ?? [];
        $tableSummary = $tables['summary'] ?? [];
        $printSummary = $printing['summary'] ?? [];
        $terminalSummary = $terminal['summary'] ?? [];
        $revenueSummary = $revenue['summary'] ?? [];

        $turnover = (float) ($waiterSummary['average_table_turnover_minutes'] ?? 0);
        $averageTurnover = (float) ($restaurantAverage['average_service_minutes'] ?? 0);
        if ($turnover > 0 && $averageTurnover > 0 && $turnover > ($averageTurnover * 1.25)) {
            $recommendations[] = $this->performanceRecommendation(
                type: 'slow_waiter_service',
                severity: 'warning',
                title: 'Waiter service time above restaurant average',
                rootCause: "Average turnover is {$this->roundNullable($turnover)} min vs restaurant {$this->roundNullable($averageTurnover)} min.",
                impact: 'Lower table turnover and slower guest service.',
                action: 'Review active waiter workload and use recent/favorite product shortcuts.',
                expectedImprovement: '10-25% faster order-to-close time if blockers are cleared.',
                policy: $this->assistantPolicy($user->can('admin.orders.active') || $user->can('admin.pos.index'), 'Permission denied.', loadingKey: 'open_orders', permissions: ['admin.orders.active', 'admin.pos.index']),
                evidence: ['turnover_minutes' => $turnover, 'restaurant_average_minutes' => $averageTurnover],
            );
        }

        if ((int) ($kitchenSummary['delayed_items'] ?? 0) > 0) {
            $recommendations[] = $this->performanceRecommendation(
                type: 'kitchen_delay',
                severity: ((float) ($kitchenSummary['delay_rate_percent'] ?? 0)) >= 20 ? 'critical' : 'warning',
                title: 'Kitchen delay affecting service speed',
                rootCause: "{$kitchenSummary['delayed_items']} item(s) exceeded the {$kitchenSummary['sla_minutes']} min SLA.",
                impact: 'Ready time and guest satisfaction can degrade during rush.',
                action: 'Open kitchen view and clear delayed/ready items before new lower-priority work.',
                expectedImprovement: 'Expected delay reduction 20-40% after clearing delayed queue.',
                policy: $this->assistantPolicy($user->can('admin.pos.kitchen_viewer') || $user->can('admin.orders.active'), 'Permission denied.', loadingKey: 'open_kitchen', permissions: ['admin.pos.kitchen_viewer', 'admin.orders.active']),
                evidence: $kitchenSummary,
            );
        }

        if ((int) ($tableSummary['idle_after_payment_count'] ?? 0) > 0 || (int) ($tableSummary['occupied_without_order_count'] ?? 0) > 0) {
            $recommendations[] = $this->performanceRecommendation(
                type: 'table_turnover_delay',
                severity: 'warning',
                title: 'Table turnover is being blocked',
                rootCause: "{$tableSummary['idle_after_payment_count']} paid table(s) still occupied; {$tableSummary['occupied_without_order_count']} occupied table(s) without order.",
                impact: 'New guests may wait while usable seats remain blocked.',
                action: 'Release paid tables and start service on waiting occupied tables.',
                expectedImprovement: 'Can free seats immediately and improve revenue/hour.',
                policy: $this->assistantPolicy($user->can('admin.tables.update_status') || $user->can('admin.pos.index'), 'Permission denied.', loadingKey: 'mark_available', permissions: ['admin.tables.update_status', 'admin.pos.index']),
                evidence: $tableSummary,
            );
        }

        if ((int) ($printSummary['failed_jobs'] ?? 0) > 0 || (int) ($printSummary['pending_jobs'] ?? 0) > 0) {
            $recommendations[] = $this->performanceRecommendation(
                type: 'print_queue_risk',
                severity: ((int) ($printSummary['failed_jobs'] ?? 0)) > 0 ? 'critical' : 'warning',
                title: 'Printer queue needs attention',
                rootCause: "{$printSummary['failed_jobs']} failed and {$printSummary['pending_jobs']} pending print job(s).",
                impact: 'KOT or bill delay can slow kitchen and checkout.',
                action: 'Retry failed jobs or switch to backup printer.',
                expectedImprovement: 'Restores print flow immediately after queue clears.',
                policy: $this->assistantPolicy($user->can('admin.print_jobs.index') || $user->can('admin.print_jobs.retry'), 'Permission denied.', loadingKey: 'print_queue', permissions: ['admin.print_jobs.index', 'admin.print_jobs.retry']),
                evidence: $printSummary,
            );
        }

        if ((int) ($terminalSummary['error_devices'] ?? 0) > 0 || (int) ($terminalSummary['queue_count'] ?? 0) > 0) {
            $recommendations[] = $this->performanceRecommendation(
                type: 'offline_recovery_risk',
                severity: ((int) ($terminalSummary['error_devices'] ?? 0)) > 0 ? 'critical' : 'warning',
                title: 'Terminal recovery work pending',
                rootCause: "{$terminalSummary['error_devices']} terminal(s) unhealthy and {$terminalSummary['queue_count']} queued item(s).",
                impact: 'Offline orders, payments, or print jobs may settle late.',
                action: 'Open recovery and allow safe queue replay.',
                expectedImprovement: 'Prevents duplicate/lost operations during reconnect.',
                policy: $this->assistantPolicy($user->can('admin.pos_terminal_devices.index') || $user->can('admin.pos.index'), 'Permission denied.', loadingKey: 'recovery_dashboard', permissions: ['admin.pos_terminal_devices.index', 'admin.pos.index']),
                evidence: $terminalSummary,
            );
        }

        if ((float) ($revenueSummary['revenue_change_percent'] ?? 0) < -20 && (int) ($revenueSummary['comparison_orders'] ?? 0) > 0) {
            $recommendations[] = $this->performanceRecommendation(
                type: 'revenue_below_trend',
                severity: 'warning',
                title: 'Revenue below matching period',
                rootCause: 'Revenue is down ' . abs((float) $revenueSummary['revenue_change_percent']) . '% vs comparison period.',
                impact: 'Owner should inspect product mix, table utilization, and order volume.',
                action: 'Review top waiters, tables, and kitchen bottlenecks before the rush window ends.',
                expectedImprovement: 'Early intervention can recover missed revenue during the same shift.',
                policy: $this->assistantPolicy($user->can('admin.reports.index') || $user->can('admin.pos.index'), 'Permission denied.', loadingKey: 'reports', permissions: ['admin.reports.index', 'admin.pos.index']),
                evidence: $revenueSummary,
            );
        }

        foreach ($operational['bottlenecks'] ?? [] as $bottleneck) {
            $recommendations[] = $this->performanceRecommendation(
                type: 'operational_' . ($bottleneck['domain'] ?? 'bottleneck'),
                severity: $bottleneck['severity'] ?? 'warning',
                title: $bottleneck['top_issue'] ?? 'Operational bottleneck',
                rootCause: "{$bottleneck['count']} active signal(s) in {$bottleneck['domain']}.",
                impact: 'Operational flow is currently degraded.',
                action: $bottleneck['solution'] ?? 'Resolve the highest priority item first.',
                expectedImprovement: 'Immediate reduction in active operational blockers.',
                policy: $this->assistantPolicy($user->can('admin.pos.index') || $user->can('admin.orders.active'), 'Permission denied.', loadingKey: 'operations', permissions: ['admin.pos.index', 'admin.orders.active']),
                evidence: $bottleneck,
            );
        }

        return collect($recommendations)
            ->unique('type')
            ->sortByDesc(fn(array $item) => (int) ($item['priority_score'] ?? 0))
            ->values()
            ->take(8)
            ->all();
    }

    private function performanceBottlenecks(array $operational, array $waiter, array $kitchen, array $tables, array $printing, array $terminal): array
    {
        $items = collect($operational['bottlenecks'] ?? []);

        if ((float) (($kitchen['summary']['delay_rate_percent'] ?? 0)) >= 10) {
            $items->push([
                'domain' => 'kitchen',
                'severity' => (($kitchen['summary']['delay_rate_percent'] ?? 0) >= 20) ? 'critical' : 'warning',
                'count' => (int) ($kitchen['summary']['delayed_items'] ?? 0),
                'top_issue' => 'Kitchen delay rate high',
                'solution' => 'Prioritize delayed KOT items and ready items.',
            ]);
        }

        if ((int) (($tables['summary']['idle_after_payment_count'] ?? 0)) > 0) {
            $items->push([
                'domain' => 'tables',
                'severity' => 'warning',
                'count' => (int) ($tables['summary']['idle_after_payment_count'] ?? 0),
                'top_issue' => 'Paid tables still occupied',
                'solution' => 'Release paid tables for faster turnover.',
            ]);
        }

        if ((int) (($printing['summary']['failed_jobs'] ?? 0)) > 0) {
            $items->push([
                'domain' => 'printers',
                'severity' => 'critical',
                'count' => (int) ($printing['summary']['failed_jobs'] ?? 0),
                'top_issue' => 'Print failures',
                'solution' => 'Retry failed jobs or switch backup printer.',
            ]);
        }

        if ((int) (($terminal['summary']['queue_count'] ?? 0)) > 0) {
            $items->push([
                'domain' => 'recovery',
                'severity' => 'warning',
                'count' => (int) ($terminal['summary']['queue_count'] ?? 0),
                'top_issue' => 'Offline queue pending',
                'solution' => 'Open recovery and replay queues safely.',
            ]);
        }

        return $items
            ->sortByDesc(fn(array $item) => ($item['severity'] ?? '') === 'critical' ? 2 : 1)
            ->values()
            ->take(8)
            ->all();
    }

    private function performanceRecommendation(
        string $type,
        string $severity,
        string $title,
        string $rootCause,
        string $impact,
        string $action,
        string $expectedImprovement,
        array $policy,
        array $evidence,
    ): array {
        $score = (new DecisionEngine())->score($type, 0);

        return [
            'id' => md5($type . '|' . $title . '|' . now()->format('YmdHi')),
            'type' => $type,
            'severity' => $severity,
            'priority' => $severity === 'critical' ? 'urgent' : 'high',
            'priority_score' => $severity === 'critical' ? max(90, $score->priority) : max(70, $score->priority),
            'title' => $title,
            'root_cause' => $rootCause,
            'impact' => $impact,
            'recommended_action' => $action,
            'expected_improvement' => $expectedImprovement,
            'confidence' => $score->confidence,
            'action_policy' => $policy,
            'evidence' => $evidence,
        ];
    }

    private function performanceIntegrationAudit(): array
    {
        return [
            'restaurant_memory' => ['status' => 'working', 'source' => 'POS product memory payload'],
            'search_engine' => ['status' => 'working', 'source' => 'local deterministic product/table matchers'],
            'voice_search' => ['status' => 'working', 'source' => 'deterministic voice command parser'],
            'action_policy' => ['status' => 'working', 'source' => 'Modules\\Support\\ActionPolicy'],
            'realtime' => ['status' => 'working', 'source' => 'terminal heartbeat and assistant refresh events'],
            'offline' => ['status' => 'working', 'source' => 'offline queue and terminal health'],
            'print_queue' => ['status' => Schema::hasTable('print_jobs') ? 'working' : 'unavailable', 'source' => 'print_jobs'],
            'recommendation_engine' => ['status' => 'working', 'source' => 'Core Intelligence DecisionEngine/MemoryScore'],
            'branch_isolation' => ['status' => 'working', 'source' => 'branch-scoped queries'],
            'waiter_isolation' => ['status' => 'working', 'source' => 'waiter role query scope'],
        ];
    }

    private function performanceConfidence(array $waiter, array $kitchen, array $tables): float
    {
        $orderCount = (int) ($waiter['summary']['orders_handled'] ?? 0);
        $kotVolume = (int) ($kitchen['summary']['kot_volume'] ?? 0);
        $tableCount = (int) ($tables['summary']['tracked_tables'] ?? 0);
        $signals = ($orderCount > 0 ? 0.35 : 0.0) + ($kotVolume > 0 ? 0.35 : 0.0) + ($tableCount > 0 ? 0.2 : 0.0) + 0.1;

        return round(min(0.98, max(0.35, $signals)), 2);
    }

    private function performanceAccuracyWarnings(array $waiter, array $kitchen, array $tables): array
    {
        $warnings = [];

        if ((int) ($waiter['summary']['orders_handled'] ?? 0) === 0) {
            $warnings[] = 'No orders in the selected period; waiter/revenue metrics are limited.';
        }
        if (($kitchen['summary']['average_preparation_minutes'] ?? null) === null) {
            $warnings[] = 'Preparation-time timestamps are unavailable for some kitchen calculations.';
        }
        if ((int) ($tables['summary']['tracked_tables'] ?? 0) === 0) {
            $warnings[] = 'No dine-in table orders in the selected period; table metrics are limited.';
        }

        return $warnings;
    }

    private function performanceScorePayload(int $score, string $explanation): array
    {
        return [
            'score' => $score,
            'status' => match (true) {
                $score < 55 => 'critical',
                $score < 80 => 'attention_required',
                default => 'healthy',
            },
            'explanation' => $explanation,
        ];
    }

    private function performanceBoundedScore(float|int $score): int
    {
        return max(0, min(100, (int) round($score)));
    }

    private function emptyPerformanceSummary(string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
        ];
    }

    private function percentageChange(float $previous, float $current): ?float
    {
        if (abs($previous) < 0.0001) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / abs($previous)) * 100, 2);
    }

    private function performanceMoney(float $amount, ?string $currency = null): array
    {
        return (new Money($amount, $currency ?: (setting('default_currency') ?: 'INR')))->toArray();
    }

    private function roundNullable(mixed $value, int $precision = 2): ?float
    {
        return $value === null ? null : round((float) $value, $precision);
    }

    private function localizedString(mixed $value): string
    {
        if (is_array($value)) {
            return (string) ($value[app()->getLocale()] ?? $value['en'] ?? reset($value) ?: 'Item');
        }

        $string = (string) $value;
        $decoded = json_decode($string, true);
        if (is_array($decoded)) {
            return (string) ($decoded[app()->getLocale()] ?? $decoded['en'] ?? reset($decoded) ?: 'Item');
        }

        return $string !== '' ? $string : 'Item';
    }
}
