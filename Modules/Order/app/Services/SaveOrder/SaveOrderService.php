<?php

namespace Modules\Order\Services\SaveOrder;

use DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Branch\Models\Branch;
use Modules\Cart\CartItem;
use Modules\Cart\Facades\Cart;
use Modules\Cart\Models\Cart as CartModel;
use Modules\Currency\Currency;
use Modules\Currency\Models\CurrencyRate;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderProductAction;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderUpdated;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Pos\Enums\PosSubmitAction;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Factories\PrintContents\PrintKitchenContentFactory;
use Modules\Printer\Jobs\DispatchPrintJob;
use Modules\SeatingPlan\Enums\TableMergeType;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Table;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;
use Throwable;

class SaveOrderService implements SaveOrderServiceInterface
{
    private Collection $removedKitchenProducts;

    public function __construct()
    {
        $this->removedKitchenProducts = collect();
    }

    private function lockCartForSubmit(?string $cartId): void
    {
        if (blank($cartId)) {
            return;
        }

        $cartItemsKey = "cart_{$cartId}_cart_items";
        $cartConditionsKey = "cart_{$cartId}_cart_conditions";

        $cartItems = CartModel::query()
            ->whereKey($cartItemsKey)
            ->lockForUpdate()
            ->first();

        CartModel::query()
            ->whereKey($cartConditionsKey)
            ->lockForUpdate()
            ->first();

        abort_if(
            ! $cartItems || collect($cartItems->data ?? [])->isEmpty(),
            409,
            __("order::messages.cart_already_processed")
        );
    }

    /**
     * Create Order
     *
     * @param Branch $branch
     * @param User $user
     * @param array $data
     * @return Order
     */
    private function createOrder(Branch $branch, User $user, array $data): Order
    {
        $isDineIn = $data['type'] == OrderType::DineIn->value;
        $tableMergeId = null;

        $tableId = $isDineIn ? ($data['table_id'] ?? null) : null;
        $waiterId = $data['waiter_id'] ?? ($user->hasRole(DefaultRole::Waiter->value) ? $user->id : null);

        if (! is_null($tableId)) {
            $table = Table::query()
                ->with(['currentMerge', 'activeOrders'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->findOrFail($tableId);

            // ❌ Table already has active orders (capacity / business rule)
            if ($table->activeOrders->isNotEmpty()) {

                // 🧮 Existing guests
                $existingGuests = $table->activeOrders
                    ->sum(fn ($order) => (int) ($order->guest_count ?? 1));

                // 👤 New order guests
                $newGuests = (int) ($data['guest_count'] ?? 1);

                // ❌ Capacity exceeded
                abort_if(
                    ($existingGuests + $newGuests) > $table->capacity,
                    400,
                    __("seatingplan::tables.capacity_exceeded")
                );
            }

            // 👨‍🍳 Auto assign waiter logic
            if (is_null($waiterId) && ! is_null($table->assigned_waiter_id)) {
                $waiterId = $table->assigned_waiter_id;

            } elseif (! is_null($waiterId) && is_null($table->assigned_waiter_id)) {
                $table->assigned_waiter_id = $waiterId;
                $table->save();
            }

            // 🔗 Billing merge handling
            if (
                ! is_null($table->currentMerge)
                && $table->currentMerge->type === TableMergeType::Billing
            ) {
                $tableMergeId = $table->currentMerge->id;
            }
        }


        return Order::query()
            ->create([
                "branch_id" => $branch->id,
                "table_id" => $tableId,
                "customer_id" => Cart::customer()?->id(),
                "pos_register_id" => $data['register_id'],
                "pos_session_id" => $data['session_id'],
                "waiter_id" => $waiterId,
                "status" => in_array($data['submit_action'], [
                    PosSubmitAction::SendToKitchen->value,
                    PosSubmitAction::PayAndFire->value,
                ], true)
                    ? OrderStatus::Confirmed
                    : OrderStatus::Pending,
                "type" => $data['type'],
                "payment_status" => OrderPaymentStatus::Unpaid,
                "currency" => $branch->currency,
                "currency_rate" => $data['currency_rate'],
                "subtotal" => Cart::subTotal()->amount(),
                "total" => Cart::total()->amount(),
                "due_amount" => Cart::total()->amount(),
                ...($data['type'] == OrderType::DriveThru->value
                    ? [
                        "car_plate" => $data['car_plate'],
                        "car_description" => $data['car_description'],
                    ]
                    : []),
                "scheduled_at" => in_array($data['type'], [OrderType::PreOrder->value, OrderType::Catering->value])
                    ? ($data['scheduled_at'] ?? null)
                    : null,
                "guest_count" => $data['guest_count'] ?? 1,
                "notes" => $data["notes"] ?? null,
                "order_date" => now(),
                "served_at" => $isDineIn ? now() : null,
                "table_merge_id" => $tableMergeId
            ]);
    }

    /** @inheritDoc */
    public function create(array $data): Order
    {
        /** @var Branch $branch */
        $branch = Branch::withTrashed()->find($data['branch_id']);
        $user = auth()->user();

        $data['currency_rate'] = CurrencyRate::for($branch->currency);
        if (!isset($data['type'])) {
            $data['type'] = Cart::orderType()->value();
        }

        return DB::transaction(function () use ($data, $user, $branch) {
            $this->lockCartForSubmit($data['cart_id'] ?? null);
            // Checkout's validated order type is authoritative. Re-applying it
            // after taking the cart lock rebuilds taxes atomically and prevents
            // a stale cart session from producing untaxed dine-in orders.
            Cart::addOrderType(OrderType::from($data['type']));

            $order = $this->createOrder($branch, $user, $data);
            $this->storeStatusLogs($order);
            $this->updateTableStatus($order);
            $this->updateOrCreateProducts($order);
            $order->updateOrCreateTaxes(Cart::taxes());
            $order->UpdateOrCreateDiscount(Cart::discount());
            $order->syncApplicableOrderTaxes();
            $this->applyCourseFiring($order, $data);

            event(new OrderCreated($order, $this->shouldPrintKitchenTicket($data)));

            return $order;
        });
    }

    /**
     * Store Order Status Logs
     *
     * @param Order $order
     * @return void
     */
    private function storeStatusLogs(Order $order): void
    {
        $statuses = [OrderStatus::Pending];

        if ($order->status != OrderStatus::Pending) {
            $statuses[] = OrderStatus::Confirmed;
        }

        foreach ($statuses as $status) {
            $order->storeStatusLog(status: $status);
        }
    }

    /**
     * Update table status
     *
     * @param Order $order
     * @return void
     */
    private function updateTableStatus(Order $order): void
    {
        if ($order->type == OrderType::DineIn && !is_null($order->table_id)) {
            if ($order->table->status != TableStatus::Occupied) {
                $order->table->update(["status" => TableStatus::Occupied]);
                $order->table->storeStatusLog(
                    status: TableStatus::Occupied,
                    note: "ORDER_CREATED :$order->reference_no"
                );
            }
        }
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Order
    {
        /** @var Order $order */
        $order = Order::query()->with(["branch", "products"])->findOrFail($id);

        $user = auth()->user();

        $scale = Currency::subunit($order->currency);

        $data['currency_rate'] = CurrencyRate::for($order->branch->currency);
        $data['type'] ??= Cart::orderType()->value();

        return DB::transaction(function () use ($data, $order, $user, $id, $scale) {
            $this->lockCartForSubmit($data['cart_id'] ?? null);
            Cart::addOrderType(OrderType::from($data['type']));

            $order = Order::query()
                ->with(["branch", "products"])
                ->lockForUpdate()
                ->findOrFail($order->id);

            $additional = (float) collect(data_get($order->fulfilmentDetails(), 'additional_payments', []))->sum();
            $totalRound = \Modules\Order\Delivery\DeliveryMoney::add(Cart::total()->amount(), $additional, $order->currency);
            $amountPaid = round($order->total->amount() - $order->due_amount->amount(), $scale);
            $data['overpaid_amount'] = $amountPaid > $totalRound
                ? round($amountPaid - $totalRound, $scale)
                : 0;

            abort_if(
                $data['overpaid_amount'] > 0 && !isset($data['refund_payment_method']),
                400,
                __("validation.required", ["attribute" => __("order::attributes.orders.refund_payment_method")])
            );

            abort_if($totalRound <= 0, 400, __("order::messages.order_must_contain_at_least_one_active_product"));

            $order = $this->updateOrder($order, $user, $data);
            $order = $this->confirmHeldOrderIfSubmitted($order, $data);

            $changes = $this->updateOrCreateProducts($order, true);

            $order->updateOrCreateTaxes(Cart::taxes());
            $order->updateOrCreateDiscount(Cart::discount());
            $order->syncApplicableOrderTaxes();
            $order->revertOrderStatusToPreparingIfModified();
            $order->handleOverpaymentAdjustment($data);
            $this->applyCourseFiring($order, $data);
            $this->queueEditKitchenPrints($order, $data, $changes);

            event(new OrderUpdated($order));

            return $order;
        });
    }

    private function confirmHeldOrderIfSubmitted(Order $order, array $data): Order
    {
        $shouldConfirm = $order->status === OrderStatus::Pending
            && in_array($data['submit_action'] ?? null, [
                PosSubmitAction::SendToKitchen->value,
                PosSubmitAction::PayAndFire->value,
            ], true);

        if (! $shouldConfirm) {
            return $order;
        }

        $order->update(['status' => OrderStatus::Confirmed]);
        $order->storeStatusLog(status: OrderStatus::Confirmed);

        return $order->fresh();
    }

    private function shouldPrintKitchenTicket(array $data): bool
    {
        return ! array_key_exists('auto_print_kot', $data)
            || filter_var($data['auto_print_kot'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Update Order
     *
     * @param Order $order
     * @param User $user
     * @param array $data
     * @return Order
     */
    private function updateOrder(Order $order, User $user, array $data): Order
    {
        $isDineIn = $data['type'] == OrderType::DineIn->value;
        $total = \Modules\Order\Delivery\DeliveryMoney::add(Cart::total()->amount(),
            (float) collect(data_get($order->fulfilmentDetails(), 'additional_payments', []))->sum(), $order->currency);
        $tableId = $isDineIn ? ($data['table_id'] ?? null) : null;
        $tableMergeId = $order->table_merge_id;
        $waiterId = $data['waiter_id'] ?? ($user->hasRole(DefaultRole::Waiter->value) ? $user->id : null);

        if (!is_null($order->table_id) && is_null($tableId)) {
            $this->handleRemoveTableFromOrder($order);
        } else if ($order->table_id && $order->table_id != $tableId) {
            $tableData = $this->handleChangeTableOrder($order, $tableId, $waiterId);
            $waiterId = $tableData['waiter_id'];
            $tableMergeId = $tableData['table_merge_id'];
        }

        $order->update([
            "customer_id" => Cart::customer()?->id(),
            "table_id" => $tableId,
            "pos_register_id" => $data['register_id'],
            "pos_session_id" => $data['session_id'],
            "waiter_id" => $waiterId,
            "type" => $data['type'],
            "currency" => $order->branch->currency,
            "currency_rate" => $data['currency_rate'],
            "subtotal" => Cart::subTotal()->amount(),
            "total" => $total,
            "due_amount" => max($total - ($order->total->amount() - $order->due_amount->amount()), 0),
            ...($data['type'] == OrderType::DriveThru->value
                ? [
                    "car_plate" => $data['car_plate'],
                    "car_description" => $data['car_description'],
                ]
                : []),
            "scheduled_at" => in_array($data['type'], [OrderType::PreOrder->value, OrderType::Catering->value])
                ? ($data['scheduled_at'] ?? null)
                : null,
            "guest_count" => $data['guest_count'] ?? 1,
            "notes" => $data["notes"] ?? null,
            ...(array_key_exists('fulfilment', $data) ? ["fulfilment" => $data['fulfilment']] : []),
            "modified_at" => now(),
            "modified_by" => auth()->id(),
            "table_merge_id" => $tableMergeId
        ]);

        return $order->fresh();
    }

    /**
     * Handle remove table from order
     *
     * @param Order $order
     * @return void
     */
    private function handleRemoveTableFromOrder(Order $order): void
    {
        $table = Table::query()
            ->with(["currentMerge"])
            ->lockForUpdate()
            ->findOrFail($order->table_id);

        abort_if(
            !is_null($table->currentMerge) || !is_null($order->table_merge_id),
            400,
            __("order::messages.table_cannot_be_changed_because_merge_request")
        );

        $table->update(["status" => TableStatus::Available]);
        $table->storeStatusLog(
            status: TableStatus::Available,
            note: "ORDER_REMOVE_TABLE :$order->reference_no"
        );

    }

    /**
     * Handle remove table from order
     *
     * @param Order $order
     * @param int $newTableId
     * @param int|null $waiterId
     * @return array
     */
    private function handleChangeTableOrder(Order $order, int $newTableId, ?int $waiterId): array
    {
        $table = Table::query()
            ->with(["currentMerge"])
            ->lockForUpdate()
            ->findOrFail($order->table_id);

        abort_if(
            !is_null($table->currentMerge) || !is_null($order->table_merge_id),
            400,
            __("order::messages.table_cannot_be_changed_because_merge_request")
        );

        $newTable = Table::query()
            ->with(["currentMerge", "activeOrder"])
            ->lockForUpdate()
            ->findOrFail($newTableId);

        abort_unless(is_null($newTable->activeOrder), 400, __("order::messages.table_already_have_active_order"));

        $tableMergeId = null;

        if (is_null($waiterId) && !is_null($newTable->assigned_waiter_id)) {
            $waiterId = $newTable->assigned_waiter_id;
        } elseif (!is_null($waiterId) && is_null($newTable->assigned_waiter_id)) {
            $newTable->assigned_waiter_id = $waiterId;
            $newTable->save();
        }

        if (!is_null($table->currentMerge) && $table->currentMerge->type == TableMergeType::Billing) {
            $tableMergeId = $table->currentMerge->id;
        }

        if ($newTable->status != TableStatus::Occupied) {
            $newTable->update(["status" => TableStatus::Occupied]);
            $newTable->storeStatusLog(
                status: TableStatus::Occupied,
                note: "ORDER_CHANGE_TABLE :$order->reference_no:$table->id"
            );
        }

        $table->update(["status" => TableStatus::Available]);
        $table->storeStatusLog(
            status: TableStatus::Available,
            note: "ORDER_CHANGE_TABLE :$order->reference_no:$newTable->id"
        );

        return [
            "table_merge_id" => $tableMergeId,
            "waiter_id" => $waiterId,
        ];
    }

    /**
     * Update Or Create Products
     *
     * @param Order $order
     * @param bool $isUpdate
     * @return void
     * @throws Throwable
     */
    private function updateOrCreateProducts(Order $order, bool $isUpdate = false): array
    {
        $items = Cart::items();
        $addedKitchenProducts = collect();
        $originalProducts = $isUpdate ? $order->products->keyBy('id') : collect();
        $this->removedKitchenProducts = collect();

        if ($isUpdate) {
            $items = $this->parseProductActions($items, $order->products);
        } else {
            // A cart reused after edit mode must not update product rows from the previous order.
            $items->each(function (CartItem $item) {
                $item->orderProduct = null;
            });
        }

        foreach ($items as $product) {
            $isNewOrderProduct = $isUpdate && is_null($product->orderProduct?->id());
            $orderProduct = $order->updateOrCreateProduct($product);

            if (!$isUpdate) {
                continue;
            }

            if ($orderProduct->status === OrderProductStatus::Cancelled) {
                $this->removedKitchenProducts->push($orderProduct);
                continue;
            }

            if ($orderProduct->status === OrderProductStatus::Refunded) {
                continue;
            }

            if ($isNewOrderProduct) {
                $addedKitchenProducts->push($orderProduct);
                continue;
            }

            $originalProductId = $product->orderProduct?->id();
            $originalProduct = $originalProductId ? $originalProducts->get($originalProductId) : null;

            if (!$originalProduct) {
                continue;
            }

            $quantityDelta = (int)$orderProduct->quantity - (int)$originalProduct->quantity;

            if ($quantityDelta > 0) {
                $addedKitchenProducts->push($this->copyOrderProductForKitchenDelta($orderProduct, $quantityDelta));
            } elseif ($quantityDelta < 0) {
                $this->removedKitchenProducts->push($this->copyOrderProductForKitchenDelta($originalProduct, abs($quantityDelta)));
            }
        }

        return [
            'added_products' => $addedKitchenProducts->values(),
            'removed_products' => $this->removedKitchenProducts->values(),
        ];
    }

    /**
     * Parse Product actions
     *
     * @param Collection $items
     * @param Collection $orderProducts
     * @return Collection
     */
    private function parseProductActions(Collection $items, Collection $orderProducts): Collection
    {
        $data = collect();
        $orderProducts = $orderProducts->keyBy('id');
        $itemsMap = $items
            ->filter(fn($item) => !is_null($item->orderProduct))
            ->keyBy(fn($item) => $item->orderProduct->id());

        $deletedProductIds = [];
        $cancelledProductIds = [];
        /** @var OrderProduct $orderProduct */
        foreach ($orderProducts as $orderProduct) {
            if (isset($itemsMap[$orderProduct->id])) {
                continue;
            }

            if ($orderProduct->status === OrderProductStatus::Pending) {
                $deletedProductIds[] = $orderProduct->id;
                continue;
            }

            if ($orderProduct->status === OrderProductStatus::Preparing) {
                $cancelledProductIds[] = $orderProduct->id;
                continue;
            }

            if (!in_array($orderProduct->status, [OrderProductStatus::Cancelled, OrderProductStatus::Refunded], true)) {
                abort(400, __("order::messages.order_product_remove_requires_action"));
            }
        }

        if (!empty($deletedProductIds)) {
            $this->removedKitchenProducts = $this->removedKitchenProducts
                ->merge($orderProducts->only($deletedProductIds)->values())
                ->values();

            OrderProduct::query()->whereIn('id', $deletedProductIds)->delete();
        }

        if (!empty($cancelledProductIds)) {
            $this->removedKitchenProducts = $this->removedKitchenProducts
                ->merge($orderProducts->only($cancelledProductIds)->values())
                ->values();

            OrderProduct::query()
                ->whereIn('id', $cancelledProductIds)
                ->update(['status' => OrderProductStatus::Cancelled]);
        }

        /** @var CartItem $item */
        foreach ($items as $item) {

            if (!is_null($item->orderProduct) && isset($orderProducts[$item->orderProduct->id()])) {
                $exceptedQuantity = collect(array_values($item->actions))->sum('quantity');
                /** @var OrderProduct $originalProduct */
                $originalProduct = $orderProducts[$item->orderProduct->id()];
                if ($item->qty == 0) {
                    $item->qty = $exceptedQuantity;
                }
                $quantity = $item->qty;
                if (in_array($originalProduct->status, [OrderProductStatus::Cancelled, OrderProductStatus::Refunded])) {
                    $item->orderProduct->setStatus($originalProduct->status);
                }

                if (!empty($item->actions) && ($quantity - $exceptedQuantity) >= 0) {
                    foreach ($item->actions as $action) {
                        abort_if(
                            ($action['id'] == OrderProductAction::Cancel->value && $originalProduct->status != OrderProductStatus::Preparing)
                            || $action['id'] == OrderProductAction::Refund->value && !in_array($originalProduct->status, [OrderProductStatus::Served, OrderProductStatus::Ready]),
                            400,
                            __("core::errors.an_unexpected_error_occurred")
                        );
                    }

                    if ($quantity === $exceptedQuantity) {
                        $item->orderProduct->setStatus(
                            OrderProductAction::from(array_values($item->actions)[0]['id'])->getProductStatus()
                        );
                        $item->qty = $exceptedQuantity;
                        $data->push($item);
                    } else {
                        $data->push($item);
                        $item->orderProduct->setId();
                        foreach ($item->actions as $action) {
                            $newItem = clone $item;
                            $newItem->qty = $action['quantity'];
                            $newItem->orderProduct->setStatus(
                                OrderProductAction::from($action['id'])->getProductStatus()
                            );
                            $data->push($newItem);
                        }
                    }
                } else {
                    $data->push($item);
                }
            } else {
                $data->push($item);
            }
        }

        return $data;
    }

    /**
     * Apply course fire-timing when an order is sent to the kitchen.
     *
     * Un-coursed items always fire immediately. For coursed items, only the
     * "active" courses fire now: any course already fired earlier, plus the
     * first course if none have been fired yet. Higher courses stay held
     * (fired_at null) until the waiter fires them via the fire-course endpoint.
     */
    private function applyCourseFiring(Order $order, array $data): void
    {
        $shouldFire = in_array($data['submit_action'] ?? null, [
            PosSubmitAction::SendToKitchen->value,
            PosSubmitAction::PayAndFire->value,
        ], true);

        if (! $shouldFire) {
            return;
        }

        $order->loadMissing('products');
        $coursed = $order->products->filter(fn(OrderProduct $p) => $p->course_number !== null);

        if ($coursed->isEmpty()) {
            return; // nothing coursed — default flow fires everything
        }

        $firedCourses = $coursed
            ->filter(fn(OrderProduct $p) => $p->fired_at !== null)
            ->map(fn(OrderProduct $p) => (int) $p->course_number)
            ->unique();

        if ($firedCourses->isEmpty()) {
            $firedCourses = collect([(int) $coursed->min('course_number')]);
        }

        $order->products
            ->filter(fn(OrderProduct $p) => $p->fired_at === null
                && ($p->course_number === null || $firedCourses->contains((int) $p->course_number)))
            ->each(fn(OrderProduct $p) => $p->forceFill(['fired_at' => now()])->save());

        $order->load('products');
    }

    private function queueEditKitchenPrints(Order $order, array $data, array $changes): void
    {
        if (! $this->shouldPrintKitchenTicket($data)) {
            return;
        }

        $submitAction = $data['submit_action'] ?? null;
        $shouldFire = in_array($submitAction, [
            PosSubmitAction::SendToKitchen->value,
            PosSubmitAction::PayAndFire->value,
        ], true);

        if ($submitAction === PosSubmitAction::PayAndFire->value) {
            Cache::put($this->skipPaymentKitchenPrintCacheKey($order), true, now()->addMinutes(10));
        }

        if (!$shouldFire || !$order->isScheduledForToday()) {
            return;
        }

        $factory = app(PrintKitchenContentFactory::class);
        $order->load(['waiter:id,name', 'table:id,name']);

        $addedProducts = collect($changes['added_products'] ?? [])->filter();
        if ($addedProducts->isNotEmpty()) {
            // Don't print items belonging to a still-held course; they print when
            // their course is fired. Re-query fired state to avoid stale instances.
            $firedIds = OrderProduct::query()
                ->whereIn('id', $addedProducts->pluck('id')->filter()->all())
                ->fired()
                ->pluck('id')
                ->flip();
            $addedProducts = $addedProducts->filter(
                fn($p) => $p->id === null || $firedIds->has($p->id)
            );
        }
        if ($addedProducts->isNotEmpty()) {
            $addedProducts = (new OrderProduct())->newCollection($addedProducts->all());
            $addedProducts->load('product.categories');

            $this->queuePreparedKitchenPayload($order, $factory->resourceForProducts($order, $addedProducts));
        }

        $removedProducts = collect($changes['removed_products'] ?? [])->filter();
        if ($removedProducts->isNotEmpty()) {
            $removedProducts = (new OrderProduct())->newCollection($removedProducts->all());
            $removedProducts->load('product.categories');
            $this->queuePreparedKitchenPayload(
                $order,
                $factory->resourceForProducts($order, $removedProducts, 'REMOVED ITEMS')
            );
        }
    }

    private function queuePreparedKitchenPayload(Order $order, array $payload): void
    {
        if (empty($payload['kitchens']) && collect($payload['products'] ?? [])->isEmpty()) {
            Log::info('Edit KOT skipped: no kitchen payload for changed items.', [
                'order_id' => $order->id,
                'reference_no' => $order->reference_no,
                'section_title' => $payload['section_title'] ?? null,
            ]);

            return;
        }

        DispatchPrintJob::dispatchAfterCommit(
            $order->id,
            PrintContentType::Kitchen,
            null,
            false,
            ['prepared_payload' => $payload]
        );
    }

    private function skipPaymentKitchenPrintCacheKey(Order $order): string
    {
        return "orders:{$order->id}:skip_payment_kitchen_print";
    }

    private function copyOrderProductForKitchenDelta(OrderProduct $product, int $quantity): OrderProduct
    {
        $copy = clone $product;
        $copy->setAttribute('quantity', $quantity);

        return $copy;
    }
}
