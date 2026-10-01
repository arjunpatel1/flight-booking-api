<?php

namespace Modules\Pos\Services\KitchenViewer;

use Arr;
use DB;
use Illuminate\Support\Facades\Cache;
use Modules\Branch\Models\Branch;
use Modules\Category\Models\Category;
use Modules\Notification\Enums\NotificationSeverity;
use Modules\Notification\Services\NotificationServiceInterface;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Events\OrderVoided;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Order\Support\KitchenSla;
use Modules\User\Models\User;

class KitchenViewerService implements KitchenViewerServiceInterface
{
    public function __construct(
        private readonly NotificationServiceInterface $notificationService,
    ) {}

    /** {@inheritDoc} */
    public function getOrders(?int $branchId = null): array
    {
        $user = auth()->user();
        $branchId ??= $user->effective_branch?->id;

        $categoryIds = $this->resolveKitchenCategoryIds($user);
        $cacheKey = makeCacheKey([
            'kitchen-orders',
            'branch-'.$branchId,
            'user-'.$user->id,
            'categories-'.md5(implode(',', $categoryIds)),
        ]);

        return Cache::tags(['orders', 'kitchen'])
            ->remember(
                $cacheKey,
                now()->addMinutes(1),
                function () use ($branchId, $categoryIds) {
                    $baseQuery = Order::query()
                        ->where('branch_id', $branchId)
                        ->forKitchenCategories($categoryIds)
                        ->visibleForKitchen()
                        ->activeOrders()
                        ->whereIn('status', [
                            OrderStatus::Confirmed,
                            OrderStatus::Preparing,
                            OrderStatus::Ready,
                        ]);

                    return [
                        'orders' => (clone $baseQuery)
                            ->with([
                                'table:id,name',
                                'products' => fn ($q) => $q->when(
                                    count($categoryIds),
                                    fn ($q) => $q->whereHas(
                                        'product.categories',
                                        fn ($q) => $q->whereIn('id', $categoryIds)
                                    )
                                ),
                            ])
                            ->orderByDesc('updated_at')
                            ->get(),
                        'last_updated_at' => optional((clone $baseQuery)->max('updated_at'))?->toISOString(),
                    ];
                }
            );
    }

    /**
     * Resolve kitchen category ids
     */
    private function resolveKitchenCategoryIds(User $user): array
    {
        if (empty($user->category_slugs)) {
            return [];
        }

        return Category::query()
            ->whereIn('slug', $user->category_slugs)
            ->get()
            ->flatMap(fn ($category) => $category->descendants()->pluck('id'))
            ->unique()
            ->values()
            ->all();
    }

    /** {@inheritDoc} */
    public function getConfiguration(?int $branchId = null): array
    {
        $user = auth()->user();
        $branch = null;

        $data = [
            'branches' => [],
            'order_types' => [],
            'branch_id' => $user->assignedToBranch() ? $user->branch_id : $branchId,
            'settings' => $this->kitchenSettings(),
        ];

        if (is_null($data['branch_id'])) {
            if ($user->assignedToBranch()) {
                $data['branches'][] = [
                    'id' => $user->branch->id,
                    'name' => $user->branch->name,
                    'currency' => $user->branch->currency,
                ];
                $branch = $user->branch;
            } else {
                $data['branches'] = Branch::list();
            }
        }

        if (count($data['branches']) > 0 || ! is_null($data['branch_id'])) {
            if (is_null($data['branch_id'])) {
                $data['branch_id'] = $data['branches'][0]['id'];
            }

            $branch = $branch ?? Branch::select(
                'id',
                'name',
                'order_types',
                'currency')
                ->findOrFail($data['branch_id']);

            $data['order_types'] = array_values(array_filter(
                OrderType::toArrayTrans(),
                fn ($orderType) => in_array($orderType['id'], ($branch->order_types ?: []))
            ));
        }

        return $data;
    }

    private function kitchenSettings(): array
    {
        $autoRefreshEnabled = (bool) setting('auto_refresh_enabled');

        return [
            'auto_refresh' => [
                'enabled' => $autoRefreshEnabled,
                ...($autoRefreshEnabled ? [
                    'mode' => setting('auto_refresh_mode') ?: 'smart_polling',
                    'interval' => max((int) setting('auto_refresh_interval', 10000), 1000),
                    'pause_on_idle' => (bool) setting('auto_refresh_pause_on_idle'),
                    'idle_timeout' => max((int) setting('auto_refresh_idle_timeout', 120000), 30000),
                ] : []),
            ],
            'sound_alert_enabled' => (bool) setting('kitchen_sound_alert_enabled'),
            'delayed_order_minutes' => app(KitchenSla::class)->thresholdMinutes(),
        ];
    }

    /** {@inheritDoc} */
    public function moveOrderProductToNextStatus(int|string $orderId, array|int $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', Arr::wrap($ids))));
        abort_if($ids === [], 422, __('validation.required', ['attribute' => 'ids']));

        DB::transaction(function () use ($orderId, $ids) {
            // Do not scope this lookup to the pre-update kitchen lanes. Another
            // device may have moved the order between render and click; that is
            // an idempotent stale action, not a missing record.
            $order = Order::query()
                ->where(fn ($query) => $query->where('id', $orderId)
                    ->orWhere('reference_no', $orderId))
                ->lockForUpdate()
                ->firstOrFail();

            $orderProducts = $order->products()
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            abort_unless($orderProducts->count() === count($ids), 422, 'One or more kitchen items no longer belong to this order.');

            if (in_array($order->status, [OrderStatus::Served, OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Refunded], true)) {
                return;
            }

            /** @var OrderProduct $orderProduct */
            foreach ($orderProducts as $orderProduct) {
                $nextStatus = $orderProduct->status->nextStatus();
                if ($nextStatus !== null) {
                    $orderProduct->update(['status' => $nextStatus]);
                }
            }

            $order->recalculateOrderStatus();
        });
    }

    /** {@inheritDoc} */
    public function cancelOrderProducts(int|string $orderId, array|int $ids, string $reason): void
    {
        $ids = array_filter(Arr::wrap($ids));

        abort_if(empty($ids), 422, __('validation.required', ['attribute' => 'ids']));

        $order = Order::query()
            ->with(['waiter:id,name'])
            ->where(fn ($query) => $query->where('id', $orderId)
                ->orWhere('reference_no', $orderId))
            ->whereNotIn('status', [OrderStatus::Served, OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Refunded])
            ->activeOrders()
            ->firstOrFail();

        abort_unless(
            $order->payment_status === OrderPaymentStatus::Unpaid,
            400,
            __('order::messages.order_cannot_cancel', ['status' => $order->payment_status->trans()])
        );

        DB::transaction(function () use ($order, $ids, $reason) {
            $order = Order::query()
                ->with(['waiter:id,name'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            $orderProducts = $order->products()
                ->whereIn('id', $ids)
                ->whereIn('status', [
                    OrderProductStatus::Pending,
                    OrderProductStatus::Preparing,
                ])
                ->lockForUpdate()
                ->get();

            abort_if($orderProducts->isEmpty(), 422, __('order::messages.order_must_contain_at_least_one_active_product'));

            $orderProducts->each(fn (OrderProduct $orderProduct) => $orderProduct->update([
                'status' => OrderProductStatus::Cancelled,
            ]));

            $order->storeStatusLog(
                status: $order->status,
                changedById: auth()->id(),
                note: $reason,
            );

            $order->recalculateOrderStatus();

            if ($order->waiter) {
                $items = $orderProducts
                    ->loadMissing('product:id,name')
                    ->map(fn (OrderProduct $orderProduct) => "{$orderProduct->quantity}x {$orderProduct->product?->name}")
                    ->join(', ');

                $this->notificationService->create([
                    'title' => __('notification::notifications.kitchen_item_cancel.title', [
                        'order' => $order->order_number ?: $order->reference_no,
                    ]),
                    'message' => __('notification::notifications.kitchen_item_cancel.message', [
                        'order' => $order->order_number ?: $order->reference_no,
                        'items' => $items,
                        'reason' => $reason,
                    ]),
                    'type' => 'kitchen_item_cancel',
                    'severity' => NotificationSeverity::Warning->value,
                    'icon' => 'tabler-tools-kitchen-2-off',
                    'color' => 'warning',
                    'payload' => [
                        'order_id' => $order->id,
                        'reference_no' => $order->reference_no,
                        'order_number' => $order->order_number,
                        'order_product_ids' => $orderProducts->pluck('id')->all(),
                        'items' => $items,
                        'reason' => $reason,
                        'cancelled_by' => auth()->id(),
                    ],
                ], $order->waiter);
            }
        });
    }

    /** {@inheritDoc} */
    public function cancelDelayedOrder(int|string $orderId, string $reason): void
    {
        $order = Order::query()
            ->with(['waiter:id,name'])
            ->where(fn ($query) => $query->where('id', $orderId)
                ->orWhere('reference_no', $orderId))
            ->activeOrders()
            ->firstOrFail();

        abort_unless(
            in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Preparing], true),
            400,
            __('order::messages.order_cannot_cancel', ['status' => $order->status->trans()])
        );

        abort_unless(
            $order->payment_status === OrderPaymentStatus::Unpaid,
            400,
            __('order::messages.order_cannot_cancel', ['status' => $order->payment_status->trans()])
        );

        $sla = app(KitchenSla::class);
        abort_unless(
            $order->created_at && $order->created_at <= $sla->delayedCutoff(),
            400,
            __('pos::messages.order_not_delayed')
        );

        DB::transaction(function () use ($order, $reason) {
            $order = Order::query()
                ->with(['waiter:id,name'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            abort_unless(
                in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Preparing], true),
                400,
                __('order::messages.order_cannot_cancel', ['status' => $order->status->trans()])
            );

            abort_unless(
                $order->payment_status === OrderPaymentStatus::Unpaid,
                400,
                __('order::messages.order_cannot_cancel', ['status' => $order->payment_status->trans()])
            );

            $order->update(['status' => OrderStatus::Cancelled]);

            event(new OrderUpdateStatus(
                order: $order,
                status: OrderStatus::Cancelled,
                changedById: auth()->id(),
                note: $reason,
            ));

            event(new OrderVoided(
                order: $order,
                status: OrderStatus::Cancelled,
                note: $reason,
            ));

            if ($order->waiter) {
                $this->notificationService->create([
                    'title' => __('notification::notifications.kitchen_delayed_cancel.title', [
                        'order' => $order->order_number ?: $order->reference_no,
                    ]),
                    'message' => __('notification::notifications.kitchen_delayed_cancel.message', [
                        'order' => $order->order_number ?: $order->reference_no,
                        'reason' => $reason,
                    ]),
                    'type' => 'kitchen_delayed_cancel',
                    'severity' => NotificationSeverity::Warning->value,
                    'icon' => 'tabler-alert-triangle',
                    'color' => 'warning',
                    'payload' => [
                        'order_id' => $order->id,
                        'reference_no' => $order->reference_no,
                        'order_number' => $order->order_number,
                        'reason' => $reason,
                        'cancelled_by' => auth()->id(),
                    ],
                ], $order->waiter);
            }
        });
    }
}
