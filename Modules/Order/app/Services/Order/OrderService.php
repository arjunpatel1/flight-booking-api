<?php

namespace Modules\Order\Services\Order;

use App\NexDine;
use DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\Cart\Facades\Cart;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderSourceFilter;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Enums\ReasonType;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Events\OrderVoided;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Order\Models\Reason;
use Modules\Payment\Enums\RefundPaymentMethod;
use Modules\Support\Money;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Services\ManagerApproval\PosManagerApprovalService;
use Modules\Printer\app\Factories\PrintContentFactory;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Factories\PrintContents\PrintKitchenContentFactory;
use Modules\Printer\Jobs\DispatchPrintJob;
use Modules\Printer\Services\Render\PrintRenderServiceInterface;
use Modules\Support\GlobalStructureFilters;

class OrderService implements OrderServiceInterface
{
    public function __construct(protected PosManagerApprovalService $managerApprovalService) {}

    /** @inheritDoc */
    public function label(): string
    {
        return __("order::orders.order");
    }

    /** @inheritDoc */
    public function getStructureFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                "key" => 'status',
                "label" => __('order::orders.filters.status'),
                "type" => 'select',
                "options" => OrderStatus::toArrayTrans(),
            ],
            [
                "key" => 'type',
                "label" => __('order::orders.filters.type'),
                "type" => 'select',
                "options" => OrderType::toArrayTrans(),
            ],
            [
                "key" => 'source',
                "label" => __('order::orders.filters.source'),
                "type" => 'select',
                "options" => OrderSourceFilter::toArrayTrans(),
            ],
            [
                "key" => 'payment_status',
                "label" => __('order::orders.filters.payment_status'),
                "type" => 'select',
                "options" => OrderPaymentStatus::toArrayTrans(),
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function cancel(int|string $id, array $data): void
    {
        /** @var Order $order */
        $order = $this->findOrFail($id);

        abort_unless(
            $order->cancelIsAllowed(),
            400,
            __("order::messages.order_cannot_cancel", ["status" => $order->status->trans()])
        );

        $activeSession = PosRegister::activeSession(
            $data['register_id'],
            __("admin::resource.system_cancel", ["resource" => __("order::orders.order")])
        );

        DB::transaction(function () use ($order, $data, $activeSession) {
            $order = $this->lockOrderForUpdate($order->id);

            abort_if($order->deliveryIsProviderManaged(), 409,
                'This delivery is managed by a partner. Request cancellation through the delivery support workflow.');

            abort_unless(
                $order->cancelIsAllowed(),
                400,
                __("order::messages.order_cannot_cancel", ["status" => $order->status->trans()])
            );

            $this->managerApprovalService->consume(
                $data['manager_approval_token'] ?? null,
                $order->branch_id,
                'order.cancel',
                'order',
                (string) $order->id,
                ['reason_id' => $data['reason_id'], 'note' => $data['note'] ?? null],
            );

            $order->update(['status' => OrderStatus::Cancelled]);

            event(new OrderUpdateStatus(
                order: $order,
                status: OrderStatus::Cancelled,
                reasonId: $data['reason_id'],
                changedById: auth()->id(),
                note: $data['note'] ?? null
            ));

            event(new OrderVoided(
                order: $order,
                status: OrderStatus::Cancelled,
                refundPaymentMethod: isset($data['refund_payment_method'])
                    ? RefundPaymentMethod::from($data['refund_payment_method'])
                    : null,
                posSession: $activeSession,
                note: $data['note'] ?? null
            ));
        });
    }

    /** @inheritDoc */
    public function findOrFail(int|string $id, bool $withBranch = false): Builder|array|EloquentCollection|Order
    {
        return $this->getModel()
            ->query()
            ->when($withBranch, fn(Builder $query) => $query->with("branch"))
            ->where(fn($query) => $query->where('id', $id)
                ->orWhere('reference_no', $id))
            ->firstOrFail();
    }

    private function lockOrderForUpdate(int|string $id, bool $withBranch = false): Order
    {
        return $this->getModel()
            ->query()
            ->when($withBranch, fn(Builder $query) => $query->with("branch"))
            ->where(fn($query) => $query->where('id', $id)
                ->orWhere('reference_no', $id))
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @inheritDoc */
    public function getModel(): Order
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Order::class;
    }

    /** @inheritDoc */
    public function refund(int|string $id, array $data): void
    {
        /** @var Order $order */
        $order = $this->findOrFail($id);

        abort_unless($order->refundIsAllowed(), 400, __("order::messages.order_cannot_refund"));

        $activeSession = PosRegister::activeSession(
            $data['register_id'],
            __("admin::resource.system_refund", ["resource" => __("order::orders.order")])
        );

        DB::transaction(function () use ($order, $activeSession, $data) {
            $order = $this->lockOrderForUpdate($order->id);

            abort_if($order->deliveryIsProviderManaged(), 409,
                'This delivery is managed by a partner. Reconcile the provider task before refunding the order.');

            abort_unless($order->refundIsAllowed(), 400, __("order::messages.order_cannot_refund"));

            $this->managerApprovalService->consume(
                $data['manager_approval_token'] ?? null,
                $order->branch_id,
                'order.refund',
                'order',
                (string) $order->id,
                ['reason_id' => $data['reason_id'], 'note' => $data['note'] ?? null],
            );

            $order->update(['status' => OrderStatus::Refunded]);

            event(new OrderUpdateStatus(
                order: $order,
                status: OrderStatus::Refunded,
                reasonId: $data['reason_id'],
                changedById: auth()->id(),
                note: $data['note'] ?? null
            ));

            event(new OrderVoided(
                order: $order,
                status: OrderStatus::Refunded,
                refundPaymentMethod: isset($data['refund_payment_method'])
                    ? RefundPaymentMethod::from($data['refund_payment_method'])
                    : null,
                posSession: $activeSession,
                note: $data['note'] ?? null
            ));

        });
    }

    /** @inheritDoc */
    public function getUpdateStatusMeta(int|string $id): array
    {
        $order = $this->findOrFail($id, true);
        $hasRefundAmount = $order->hasRefundAmount();

        return [
            "reasons" => Reason::list(($order->cancelIsAllowed() ? ReasonType::Cancellation : ReasonType::Refund)->value),
            // Cancel and refund both call PosRegister::activeSession(), which
            // aborts with 400 when the chosen register has no open session.
            // Offering every active register let the user pick one that could
            // never work — the request failed after they had already entered
            // manager credentials, which read as "approval was ignored".
            // Only advertise registers that can actually complete the action.
            "pos_registers" => PosRegister::list($order->branch_id, true)
                ->filter(fn (array $register): bool => isset($register['session']))
                ->values(),
            "refund_payment_methods" => $hasRefundAmount
                ? array_filter(
                    RefundPaymentMethod::toArrayTrans(),
                    fn($orderType) => in_array($orderType['id'], $order->branch->payment_methods ?: [])
                ) : [],
            "order" => [
                "id" => $order->id,
                "reference_no" => $order->reference_no,
                "refunded_amount" => $order->getRefundedAmount(),
                "is_refund" => $order->refundIsAllowed(),
                "payment_status" => $order->payment_status,
                "has_refund_amount" => $hasRefundAmount
            ],
            "manager_approval" => $this->managerApprovalService->metaForAction(
                $order->branch_id,
                $order->refundIsAllowed() ? 'order.refund' : 'order.cancel',
                'order',
                (string) $order->id,
            ),
        ];
    }

    /** @inheritDoc */
    public function moveToNextStatus(int|string $id): OrderStatus
    {
        abort_unless(
            (bool) setting('waiter_table_status_flow_enabled', true),
            400,
            __("order::messages.could_not_update_order_status")
        );

        $order = $this->findOrFail($id);
        $nextStatus = $order->next_status;

        abort_unless($order->allowUpdateStatus(), 400, __("order::messages.could_not_update_order_status"));

        return DB::transaction(function () use ($order) {
            $order = $this->lockOrderForUpdate($order->id);
            $nextStatus = $order->next_status;

            abort_unless($order->allowUpdateStatus(), 400, __("order::messages.could_not_update_order_status"));

            if ($nextStatus === OrderStatus::Served) {
                $order->products()
                    ->where('status', OrderProductStatus::Ready)
                    ->update(['status' => OrderProductStatus::Served]);

                $order->recalculateOrderStatus();

                return $order->refresh()->status;
            }

            $releasingToKitchen = $nextStatus === OrderStatus::Confirmed && ! $order->kitchen_display;
            $order->update([
                "status" => $nextStatus,
                "kitchen_display" => $nextStatus === OrderStatus::Confirmed ? true : $order->kitchen_display,
                "closed_at" => ($order->type === OrderType::DineIn && $nextStatus == OrderStatus::Completed) ? now() : null
            ]);

            event(
                new OrderUpdateStatus(
                    order: $order,
                    status: $nextStatus,
                    changedById: auth()->id(),
                    note: $releasingToKitchen ? 'KITCHEN_RELEASED_AFTER_APPROVAL' : null,
                )
            );

            return $nextStatus;
        });

    }

    /** @inheritDoc */
    public function kitchenMoveToNextStatus(int|string $id): OrderStatus
    {
        $order = $this->findOrFail($id);
        $nextStatus = $order->next_status;

        abort_if(
            is_null($nextStatus) || !in_array($nextStatus, [OrderStatus::Preparing, OrderStatus::Ready]),
            400, __("order::messages.could_not_update_order_status")
        );

        return DB::transaction(function () use ($order) {
            $order = $this->lockOrderForUpdate($order->id);
            $nextStatus = $order->next_status;

            abort_if(
                is_null($nextStatus) || !in_array($nextStatus, [OrderStatus::Preparing, OrderStatus::Ready]),
                400, __("order::messages.could_not_update_order_status")
            );

            $order->update(["status" => $nextStatus]);

            event(
                new OrderUpdateStatus(
                    order: $order,
                    status: $nextStatus,
                    changedById: auth()->id(),
                )
            );

            return $nextStatus;
        });

    }

    /** @inheritDoc */
    public function upcomingOrders(?int $branchId = null, ?int $waiterId = null): LengthAwarePaginator
    {
        return Order::query()
            ->when(!is_null($branchId), fn($query) => $query->where('branch_id', $branchId))
            ->when(!is_null($waiterId), fn($query) => $query->waiterId($waiterId))
            ->where("scheduled_at", ">=", today()->addDay())
            ->with(["products" => fn($query) => $query
                ->whereNotIn("status", [OrderProductStatus::Cancelled, OrderProductStatus::Refunded])
                ->without("taxes", "options")
                ->with(["product" => fn($query) => $query->without("branch")->select("id", "name")]),
                "customer" => fn($query) => $query->without("roles")->select("id", "name"),
                "table:id,name",
                "waiter" => fn($query) => $query->without("roles")->select("id", "name"),
            ])
            ->paymentPendingActiveOrders()
            ->latest()
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with([
                "branch:id,name",
                "customer:id,name,email,phone,phone_country_iso_code,created_at",
                "table:id,name,floor_id",
                "table.floor:id,name",
                "waiter:id,name",
                "aggregatorOrderMapping.integration:id,provider,name",
                "partnerApiOrderMapping:id,order_id",
                "whatsAppOrderSession:id,order_id",
                // OrderResource builds its action policy for every list row.
                // The policy checks whether delivery is provider-managed, so
                // load it here instead of triggering forbidden lazy loading.
                "delivery",
            ])
            ->withCount([
                "products" => fn($query) => $query
                    ->whereNotIn("status", [OrderProductStatus::Cancelled, OrderProductStatus::Refunded]),
            ])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function stats(array $filters = []): array
    {
        // Same filters as the list (status, type, source, payment_status, dates),
        // and the same automatic branch scoping via the model's global scope.
        $base = fn() => $this->getModel()->query()->filters($filters);

        $totalOrders = $base()->count();
        $totalSales = (float) $base()->sum('total');
        $average = $totalOrders > 0 ? $totalSales / $totalOrders : 0.0;

        $branchCurrency = auth()->user()?->branch?->currency;
        $money = fn(float $amount) => $branchCurrency
            ? new Money($amount, $branchCurrency)
            : Money::inDefaultCurrency($amount);

        return [
            'total_orders' => $totalOrders,
            'total_sales' => $money($totalSales)->toArray(),
            'average_order_value' => $money($average)->toArray(),
            'by_status' => $base()
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')->pluck('aggregate', 'status'),
            'by_payment_status' => $base()
                ->selectRaw('payment_status, COUNT(*) as aggregate')
                ->groupBy('payment_status')->pluck('aggregate', 'payment_status'),
        ];
    }

    /** @inheritDoc */
    public function activeOrders(?int $branchId = null, ?int $waiterId = null): LengthAwarePaginator
    {
        return Order::query()
            ->when(!is_null($branchId), fn($query) => $query->where('branch_id', $branchId))
            ->when(!is_null($waiterId), fn($query) => $query->waiterId($waiterId))
            ->where(fn($query) => $query->whereNull("scheduled_at")
                ->orWhere("scheduled_at", "<", today()->addDay())
            )
            ->with(["products" => fn($query) => $query
                ->whereNotIn("status", [OrderProductStatus::Cancelled, OrderProductStatus::Refunded])
                ->without("taxes", "options")
                ->with(["product" => fn($query) => $query->without("branch")->select("id", "name")]),
                "customer" => fn($query) => $query->without("roles")->select("id", "name"),
                "table:id,name",
                "waiter" => fn($query) => $query->without("roles")->select("id", "name"),
                "aggregatorOrderMapping.integration:id,provider,name",
                "partnerApiOrderMapping:id,order_id",
                "delivery",
                "whatsAppOrderSession:id,order_id",
            ])
            ->activeOrders()
            ->latest()
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }


    /** @inheritDoc */
    public function initEdit(int|string $id): array
    {
        $order = $this->show(
            $id,
            [
                "branch:id,name,currency",
                "customer:id,name,email,phone,phone_country_iso_code,created_at",
                "waiter:id,name",
                "products.gift",
                "products.product.files",
                "taxes",
                "table" => fn($query) => $query
                    ->select("id", 'name', 'floor_id', 'zone_id', 'capacity')
                     ->withSum(
                        ['activeOrders as active_orders_guest_count'],
                        'guest_count'
                    )
                    ->with(["floor:id,name", "zone:id,name"]),
                "discount.gift",
            ]
        );

        abort_unless($order->editIsAllowed(), 400, __("order::messages.edit_not_allowed"));

        Cart::initOrder($order);

        return [
            "form" => [
                "waiter" => $order->waiter_id ? [
                    "id" => $order->waiter_id,
                    "name" => $order->waiter?->name,
                ] : null,
                "table" => $order->table ? [
                    "id" => $order->table->id,
                    "name" => $order->table->name,
                    "floor" => $order->table->floor?->name,
                    "zone" => $order->table->zone?->name,
                    "capacity" => $order->table->capacity,
                    "guest_count" => $order->table->active_orders_guest_count,
                ] : null,
                "meta" => [
                    "notes" => $order->notes,
                    "guestCount" => $order->guest_count,
                    "carPlate" => $order->car_plate,
                    "carDescription" => $order->car_description,
                    "scheduledAt" => $order->scheduled_at,
                ],
            ],
            "refund_payment_methods" => RefundPaymentMethod::toArrayTrans(),
            "order" => [
                "id" => $order->id,
                "reference_no" => $order->reference_no,
                "due_amount" => $order->due_amount,
                "total" => $order->total,
            ]
        ];
    }

    /** @inheritDoc */
    public function show(int|string $id, ?array $with = null): Order
    {
        /** @var Order $order */
        $order = $this->getModel()
            ->query()
            ->with($with ?: [
                "branch:id,name",
                "customer:id,name,email,phone,phone_country_iso_code,created_at",
                "products.gift",
                "products.product.files",
                "taxes",
                "payments",
                "posRegister:id,name",
                "createdBy:id,name",
                "cashier:id,name",
                "waiter:id,name",
                "table:id,name",
                "mergedIntoOrder:id,order_number,reference_no",
                "mergedBy:id,name",
                "discount.gift",
                ...(auth()->user()->can("admin.invoices.index")
                    ? [
                        "invoices:id,invoice_number,invoice_kind,total,issued_at,order_id,currency,currency_rate,uuid",
                        "mergedInvoices:id,invoice_number,invoice_kind,total,issued_at,table_merge_id,currency,currency_rate,uuid",
                    ]
                    : []),
                "statusLogs" => fn($query) => $query
                    ->with([
                        "reason:id,name",
                        "changedBy:id,name"
                    ]),
                "aggregatorOrderMapping.integration:id,provider,name",
                "partnerApiOrderMapping:id,order_id",
                "whatsAppOrderSession:id,order_id",
            ])
            ->where(function ($query) use ($id) {
                if (is_numeric($id)) {
                    $query->where('id', $id);
                } else {
                    $query->where('reference_no', $id);
                }
            })
            ->firstOrFail();

        $order->loadMissing('delivery');

        if (
            !$order->payment_status->isPaid()
            && !in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Refunded, OrderStatus::Merged], true)
        ) {
            $order->syncApplicableOrderTaxes();
            $order->load($with ?: [
                "branch:id,name",
                "customer:id,name,email,phone,phone_country_iso_code,created_at",
                "products.gift",
                "taxes",
                "payments",
                "posRegister:id,name",
                "createdBy:id,name",
                "cashier:id,name",
                "waiter:id,name",
                "table:id,name",
                "mergedIntoOrder:id,order_number,reference_no",
                "mergedBy:id,name",
                "discount.gift",
                ...(auth()->user()->can("admin.invoices.index")
                    ? [
                        "invoices:id,invoice_number,invoice_kind,total,issued_at,order_id,currency,currency_rate,uuid",
                        "mergedInvoices:id,invoice_number,invoice_kind,total,issued_at,table_merge_id,currency,currency_rate,uuid",
                    ]
                    : []),
                "statusLogs" => fn($query) => $query
                    ->with([
                        "reason:id,name",
                        "changedBy:id,name"
                    ]),
                "aggregatorOrderMapping.integration:id,provider,name",
                "partnerApiOrderMapping:id,order_id",
                "whatsAppOrderSession:id,order_id",
            ]);
        }

        return $order;
    }

    /** @inheritDoc */
    public function previewPrint(int|string $id, PrintContentType $type, ?int $kitchenId = null): string
    {
        $order = $this->getModel()
            ->query()
            ->where(fn($query) => $query->where('id', $id)
                ->orWhere('reference_no', $id))
            ->when(
                $type == PrintContentType::Invoice,
                fn($query) => $query->where('payment_status', OrderPaymentStatus::Paid)
            )
            ->firstOrFail();

        $factory = PrintContentFactory::resolve($type);

        $order->load($factory->relations());
        $payload = $factory->resource($order);

        if ($type == PrintContentType::Kitchen) {
            $kitchens = (array) ($payload['kitchens'] ?? []);
            $selectedKitchen = $kitchens[$kitchenId ?: (array_key_first($kitchens) ?? 0)] ?? null;
            $products = is_array($selectedKitchen) && array_key_exists('products', $selectedKitchen)
                ? $selectedKitchen['products']
                : ($payload['products'] ?? []);

            $payload = [
                ...$payload,
                'kitchen' => is_array($selectedKitchen) ? ($selectedKitchen['kitchen'] ?? null) : null,
                'products' => $products,
            ];
        }

        return app(PrintRenderServiceInterface::class)->renderToHtml($type, $payload);
    }

    /** @inheritDoc */
    public function print(int|string $id, PrintContentType $type, ?int $specificId = null): void
    {
        $order = $this->getModel()
            ->query()
            ->where(fn($query) => $query->where('id', $id)
                ->orWhere('reference_no', $id))
            ->when(
                $type == PrintContentType::Invoice,
                fn($query) => $query->where('payment_status', OrderPaymentStatus::Paid)
            )
            ->firstOrFail();

        DispatchPrintJob::dispatchSync(
            $order->id,
            $type,
            $specificId,
            true,
            ['fail_if_unroutable' => true]
        );
    }

    /** @inheritDoc */
    public function printMeta(int|string $id, ?int $branchId = null, ?int $registerId = null): array
    {
        /** @var Order $order */
        $order = $this->getModel()
            ->query()
            ->with(["products.product.categories"])
            ->where(fn($query) => $query->where('id', $id)
                ->orWhere('reference_no', $id))
            ->withoutCanceledOrders()
            ->firstOrFail();


        $user = auth()->user();
        $effectiveBranchId = $user->assignedToBranch()
            ? $user->branch_id
            : (!is_null($branchId) ? $branchId : $user->effective_branch->id);
        $hasPrintableInvoice = $order->payment_status->isPaid() && ! is_null($order->getInvoice());

        $data = [
            "registers" => [],
            "branches" => [],
            "contents" => [
                [
                    "type" => PrintContentType::Bill->toTrans(),
                    "label" => __("order::orders.print_actions.bill")
                ],
                ...($hasPrintableInvoice ? [[
                    "type" => PrintContentType::Invoice->toTrans(),
                    "label" => __("order::orders.print_actions.invoice")
                ]] : []),
                [
                    "type" => PrintContentType::Waiter->toTrans(),
                    "label" => __("order::orders.print_actions.waiter")
                ]
            ],
            "branch_id" => $effectiveBranchId
        ];

        if (is_null($registerId)) {
            $data['registers'] = PosRegister::list($effectiveBranchId);
            if (!$user->assignedToBranch() && is_null($branchId)) {
                $data['branches'] = Branch::list();
            }
        }

        if ($order->status != OrderStatus::Completed) {
            $factory = new PrintKitchenContentFactory;
            $kitchens = $factory->getKitchens($order->branch_id);
            foreach ($kitchens as $kitchen) {
                $kitchenSlugs = $kitchen->category_slugs;

                if (empty($kitchenSlugs)) {
                    $data["contents"][$kitchen->id] = [
                        "id" => $kitchen->id,
                        "label" => $kitchen->name,
                        "type" => PrintContentType::Kitchen->toTrans(),
                    ];
                    continue;
                }

                $allowedSlugs = $factory->resolveCategoryTreeSlugs($kitchenSlugs);

                $filteredProducts = $order->products
                    ->filter(function (OrderProduct $orderProduct) use ($allowedSlugs) {
                        return $orderProduct->product->categories->isEmpty()
                            || $orderProduct->product->categories
                                ->pluck('slug')
                                ->intersect($allowedSlugs)
                                ->isNotEmpty();
                    });

                if ($filteredProducts->isNotEmpty()) {
                    $data["contents"][$kitchen->id] = [
                        "id" => $kitchen->id,
                        "label" => $kitchen->name,
                        "type" => PrintContentType::Kitchen->toTrans(),
                    ];
                }
            }
            $data["contents"] = array_values($data["contents"]);
        }

        return $data;
    }
}
