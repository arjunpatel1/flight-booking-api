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
use Modules\Order\Support\KitchenSla;
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

trait HandlesWaiterAssistant
{
    public function waiterAssistant(?int $branchId = null): array
    {
        $user = auth()->user();
        $branchId = $user->assignedToBranch() ? $user->branch_id : $branchId;

        if (! filter_var(setting('pos_risk_notifications_enabled', true), FILTER_VALIDATE_BOOL)) {
            return [
                'cards' => [],
                'next_action' => null,
                'notifications_enabled' => false,
            ];
        }

        $cacheKey = makeCacheKey([
            'waiter-assistant',
            'branch-' . ($branchId ?? 'all'),
            'user-' . $user->id,
            'role-' . ($user->hasRole(DefaultRole::Waiter->value) ? 'waiter' : 'operator'),
            'minute-' . now()->format('YmdHi'),
        ]);

        return $this->cacheWithTags([
            'orders',
            'print_jobs',
            'tables',
            'table_reservations',
            'pos_sessions',
            'pos_terminal_devices',
            'waiter-assistant',
        ])
            ->remember($cacheKey, now()->addSeconds(25), function () use ($branchId, $user) {
                // Compute kitchen load + idle-table set once — each shared by its
                // card and its prediction (single query, no duplicate work).
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

                $sortedCards = $this->sortAssistantCards($cards);
                $nextAction = $this->assistantNextAction($sortedCards);
                if ($nextAction) {
                    $sortedCards = $sortedCards
                        ->map(fn(array $card) => [
                            ...$card,
                            'is_next_action' => ($card['id'] ?? null) === ($nextAction['id'] ?? null),
                            'autopilot_rank_reason' => ($card['id'] ?? null) === ($nextAction['id'] ?? null)
                                ? $this->assistantNextActionReason($card)
                                : null,
                        ])
                        ->values();
                }

                // Shared NexDine Intelligence Layer context + deterministic
                // predictions, surfaced through the existing assistant response.
                $context = (new ContextEngine())->current($branchId);
                $predictionEngine = new PredictionEngine();
                $kitchenSla = app(KitchenSla::class)->thresholdMinutes();
                $minutesSincePaid = (int) ($idleTables->max(fn(Order $order) => $this->assistantElapsedMinutes(
                    $order->updated_at?->toISOString() ?: $order->created_at?->toISOString()
                )) ?? 0);
                $predictions = [
                    $predictionEngine->predictRushHour($context)->toArray(),
                    $predictionEngine->predictKitchenDelay($kitchen['active'], $kitchenSla)->toArray(),
                    $predictionEngine->predictTableRelease($minutesSincePaid)->toArray(),
                ];

                return [
                    'branch_id' => $branchId,
                    'generated_at' => now()->toISOString(),
                    'context' => $context->toArray(),
                    'predictions' => $predictions,
                    'autopilot' => [
                        'mode' => 'deterministic',
                        'policy' => 'single_next_action',
                        'health_score' => $health['score'] ?? null,
                        'next_action_id' => $nextAction['id'] ?? null,
                        'decision_count' => $sortedCards->count(),
                        'generated_at' => now()->toISOString(),
                    ],
                    'next_action' => $nextAction,
                    'restaurant_health' => $health,
                    'waiter_assignment' => $this->waiterAssignmentSuggestion($branchId, $user),
                    'bottlenecks' => $this->assistantBottlenecks($sortedCards->all()),
                    'operation_timeline' => $this->assistantOperationTimeline($sortedCards->all(), $health),
                    'cards' => $sortedCards
                        ->take(12)
                        ->all(),
                ];
            });
    }

}
