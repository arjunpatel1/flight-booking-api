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

trait HandlesPerformanceCore
{
    public function performanceIntelligence(?int $branchId = null, string $period = 'today'): array
    {
        $user = auth()->user();
        $branchId = $user->assignedToBranch() ? $user->branch_id : $branchId;
        $periodWindow = $this->performancePeriod($period);

        $cacheKey = makeCacheKey([
            'performance-intelligence',
            'branch-' . ($branchId ?? 'all'),
            'user-' . $user->id,
            'role-' . ($user->hasRole(DefaultRole::Waiter->value) ? 'waiter' : 'operator'),
            'period-' . $periodWindow['key'],
            'from-' . $periodWindow['from']->format('YmdHi'),
            'to-' . $periodWindow['to']->format('YmdHi'),
            'minute-' . now()->format('YmdHi'),
        ]);

        return $this->cacheWithTags(['orders', 'print-jobs', 'waiter-assistant', 'performance-intelligence'])
            ->remember($cacheKey, now()->addSeconds(45), function () use ($branchId, $user, $periodWindow) {
                $operational = $this->performanceOperationalSnapshot($branchId, $user);
                $waiter = $this->waiterPerformanceIntelligence($branchId, $user, $periodWindow);
                $kitchen = $this->kitchenPerformanceIntelligence($branchId, $user, $periodWindow);
                $tables = $this->tablePerformanceIntelligence($branchId, $user, $periodWindow);
                $printing = $this->printPerformanceIntelligence($branchId, $periodWindow);
                $terminal = $this->terminalPerformanceIntelligence($branchId, $user);
                $revenue = $this->revenuePerformanceIntelligence($branchId, $user, $periodWindow);
                $scores = $this->performanceScores($operational, $waiter, $kitchen, $tables, $printing, $terminal, $revenue);
                $recommendations = $this->performanceRecommendations(
                    operational: $operational,
                    waiter: $waiter,
                    kitchen: $kitchen,
                    tables: $tables,
                    printing: $printing,
                    terminal: $terminal,
                    revenue: $revenue,
                    scores: $scores,
                    user: $user,
                );

                return [
                    'branch_id' => $branchId,
                    'generated_at' => now()->toISOString(),
                    'period' => [
                        'key' => $periodWindow['key'],
                        'label' => $periodWindow['label'],
                        'from' => $periodWindow['from']->toISOString(),
                        'to' => $periodWindow['to']->toISOString(),
                        'comparison_from' => $periodWindow['comparison_from']->toISOString(),
                        'comparison_to' => $periodWindow['comparison_to']->toISOString(),
                    ],
                    'context' => (new ContextEngine())->current($branchId)->toArray(),
                    'implementation_audit' => $this->performanceIntegrationAudit(),
                    'scores' => $scores,
                    'restaurant_health' => $operational['health'],
                    'waiter_performance' => $waiter,
                    'kitchen_performance' => $kitchen,
                    'table_performance' => $tables,
                    'printer_performance' => $printing,
                    'terminal_performance' => $terminal,
                    'revenue_performance' => $revenue,
                    'bottlenecks' => $this->performanceBottlenecks($operational, $waiter, $kitchen, $tables, $printing, $terminal),
                    'recommendations' => $recommendations,
                    'operation_timeline' => $operational['timeline'],
                    'accuracy' => [
                        'source' => 'live_orders_tables_print_jobs_terminal_heartbeats',
                        'mode' => 'deterministic',
                        'confidence' => $this->performanceConfidence($waiter, $kitchen, $tables),
                        'warnings' => $this->performanceAccuracyWarnings($waiter, $kitchen, $tables),
                    ],
                ];
            });
    }

    private function performancePeriod(string $period): array
    {
        $key = in_array($period, ['today', 'yesterday', 'week', 'month'], true) ? $period : 'today';
        $now = now();

        return match ($key) {
            'yesterday' => [
                'key' => 'yesterday',
                'label' => 'Yesterday',
                'from' => $now->copy()->subDay()->startOfDay(),
                'to' => $now->copy()->subDay()->endOfDay(),
                'comparison_from' => $now->copy()->subDays(2)->startOfDay(),
                'comparison_to' => $now->copy()->subDays(2)->endOfDay(),
            ],
            'week' => [
                'key' => 'week',
                'label' => 'This week',
                'from' => $now->copy()->startOfWeek(),
                'to' => $now->copy(),
                'comparison_from' => $now->copy()->subWeek()->startOfWeek(),
                'comparison_to' => $now->copy()->subWeek(),
            ],
            'month' => [
                'key' => 'month',
                'label' => 'This month',
                'from' => $now->copy()->startOfMonth(),
                'to' => $now->copy(),
                'comparison_from' => $now->copy()->subMonthNoOverflow()->startOfMonth(),
                'comparison_to' => $now->copy()->subMonthNoOverflow(),
            ],
            default => [
                'key' => 'today',
                'label' => 'Today',
                'from' => $now->copy()->startOfDay(),
                'to' => $now->copy(),
                'comparison_from' => $now->copy()->subDay()->startOfDay(),
                'comparison_to' => $now->copy()->subDay(),
            ],
        };
    }

    private function performanceOperationalSnapshot(?int $branchId, User $user): array
    {
        $kitchen = $this->kitchenLoadCounts($branchId, $user);
        $idleTables = $this->tableIdleAfterPaymentOrders($branchId, $user);
        $cards = [
            ...$this->waitingTableCards($branchId, $user),
            ...$this->readyOrderCards($branchId, $user),
            ...$this->delayedOrderCards($branchId, $user),
            ...$this->paymentPendingCards($branchId, $user),
            ...$this->tableIdleAfterPaymentCards($branchId, $user, $idleTables),
            ...$this->kitchenLoadCards($branchId, $user, $kitchen),
            ...$this->waiterLoadBalanceCards($branchId, $user),
            ...$this->printQueueCards($branchId, $user),
            ...$this->terminalRecoveryCards($branchId, $user),
            ...$this->reservationCards($branchId, $user),
            ...$this->cashSessionCards($branchId, $user),
        ];
        $health = $this->restaurantHealthScore($branchId, $user, $cards);
        if (($health['status'] ?? 'healthy') !== 'healthy') {
            $cards[] = $this->restaurantHealthCard($health, $user);
        }

        $sorted = $this->sortAssistantCards($cards);

        return [
            'cards' => $sorted->all(),
            'health' => $health,
            'bottlenecks' => $this->assistantBottlenecks($sorted->all()),
            'timeline' => $this->assistantOperationTimeline($sorted->all(), $health),
        ];
    }

    private function performanceOrderQuery(?int $branchId, User $user, array $periodWindow, bool $scopeWaiter = true): \Illuminate\Database\Query\Builder
    {
        return DB::table('orders')
            ->when($branchId, fn($query) => $query->where('orders.branch_id', $branchId))
            ->when(
                $scopeWaiter && $user->hasRole(DefaultRole::Waiter->value),
                fn($query) => $query->where('orders.waiter_id', $user->id)
            )
            ->whereBetween('orders.created_at', [$periodWindow['from'], $periodWindow['to']]);
    }

}
