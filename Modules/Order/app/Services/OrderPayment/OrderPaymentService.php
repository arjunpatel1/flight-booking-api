<?php

namespace Modules\Order\Services\OrderPayment;

use DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Currency\Currency;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Events\OrderMergeBillingPaid;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Models\Order;
use Modules\Order\Services\Order\OrderServiceInterface;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Enums\PaymentMode;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentType;
use Modules\Payment\Gateways\Data\GatewayChargeRequest;
use Modules\Payment\Gateways\Data\GatewayChargeResult;
use Modules\Payment\Gateways\PaymentGatewayManager;
use Modules\Payment\Models\Payment;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Models\PosRegister;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Jobs\DispatchPrintJob;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\TableMerge;
use Modules\Support\Money;
use Throwable;


class OrderPaymentService implements OrderPaymentServiceInterface
{
    public function __construct(
        private readonly OrderPaymentAuthorization $authorization,
    ) {
    }

    /** @inheritDoc */
    public function storePayment(int|string $id, array $data): void
    {
        $order = app(OrderServiceInterface::class)->findOrFail($id);
        $user = auth()->user();

        if (!is_null($order->table_merge_id)) {
            $orders = app(OrderServiceInterface::class)
                ->getModel()
                ->query()
                ->activeOrders()
                ->with("table")
                ->where("table_merge_id", $order->table_merge_id)
                ->get();

            $this->authorization->authorizeMany($orders, $user);
            $this->storePaymentMerge($orders, $data);
            return;
        }

        $this->authorization->authorize($order, $user);
        abort_unless($order->allowAddPayment(), 400, __("order::messages.order_payment_not_allowed"));
        $order->syncApplicableOrderTaxes();
        $syncedOrderUpdatedAt = $order->updated_at?->copy();

        $scale = Currency::subunit($order->currency);
        $factor = 10 ** $scale;

        $dueRounded = round($order->due_amount->amount(), $scale);
        $dueMinor = (int)round($dueRounded * $factor);

        $paymentMinorTotal = 0;
        foreach ($data['payments'] as $p) {
            $paymentMinorTotal += (int)round(($p['amount'] ?? 0) * $factor);
        }

        $dueAmount = $dueMinor / $factor;
        $amountToBePaid = $paymentMinorTotal / $factor;

        abort_if(
            $amountToBePaid > $dueAmount,
            400,
            __("order::messages.payment_overpaid", [
                "paid" => number_format($amountToBePaid, $scale),
                "total" => number_format($dueAmount, $scale)
            ])
        );

        abort_if(
            $data['payment_mode'] == PaymentMode::Full->value && $amountToBePaid < $dueAmount,
            400,
            __("order::messages.insufficient_payment", [
                "paid" => number_format($amountToBePaid, $scale),
                "total" => number_format($dueAmount, $scale)
            ])
        );

        $session = PosSession::query()
            ->where('branch_id', $order->branch_id)
            ->where('pos_register_id', $data['register_id'])
            ->findOrFail($data['session_id']);

        $payments = [];
        foreach ($data['payments'] as $index => $payment) {
            $minor = (int)round($payment['amount'] * $factor);
            $payments[] = $this->preparePaymentRow(
                order: $order,
                payment: [
                    "cashier_id" => $user->id,
                    "method" => $payment['method'],
                    "amount" => $minor / $factor,
                    "transaction_id" => $payment['transaction_id'] ?? null,
                    "meta" => $payment['meta'] ?? null,
                    "session" => $session,
                ],
                index: $index,
                scale: $scale,
                rawPayment: $payment,
            );
        }

        $waiterStatusFlowEnabled = (bool) setting('waiter_table_status_flow_enabled', true);

        DB::transaction(function () use ($order, $data, $payments, $paymentMinorTotal, $user, $session, $dueMinor, $scale, $factor, $syncedOrderUpdatedAt, $waiterStatusFlowEnabled) {
            $order = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->id);
            $this->authorization->authorize($order, $user);

            if (!$syncedOrderUpdatedAt || !$order->updated_at?->equalTo($syncedOrderUpdatedAt)) {
                $order->syncApplicableOrderTaxes();
            }

            $currentDueMinor = (int)round(round($order->due_amount->amount(), $scale) * $factor);

            abort_if(
                $currentDueMinor !== $dueMinor || !$order->allowAddPayment(),
                409,
                __("order::messages.order_payment_not_allowed")
            );

            $isOrderComplete = ($paymentMinorTotal === $currentDueMinor)
                && ! $order->deliveryIsProviderManaged()
                && ($order->next_status == OrderStatus::Completed || !$waiterStatusFlowEnabled);

            $order->storePayments($payments);
            $updateData = [
                "closed_at" => $isOrderComplete && $order->type === OrderType::DineIn ? now() : null,
                "cashier_id" => $user->id,
                "pos_session_id" => $order->pos_session_id ?: $session->id
            ];

            $newStatus = null;
            if ($isOrderComplete) {
                $newStatus = OrderStatus::Completed;
            } else if ($order->status == OrderStatus::Pending && $order->isScheduledForToday()) {
                $newStatus = OrderStatus::Confirmed;
            }

            $releasingToKitchen = $newStatus === OrderStatus::Confirmed && ! $order->kitchen_display;
            if (!is_null($newStatus)) {
                $updateData['status'] = $newStatus;
            }
            if ($releasingToKitchen) {
                $updateData['kitchen_display'] = true;
            }

            $order->update($updateData);

            if (!is_null($newStatus)) {
                event(
                    new OrderUpdateStatus(
                        order: $order,
                        status: $newStatus,
                        changedById: $user->id,
                        note: $releasingToKitchen ? 'KITCHEN_RELEASED_AFTER_PAYMENT' : null,
                    )
                );
            }

            $this->dispatchPrint($order, $data);

        });
    }

    /**
     * Store payment for merge orders
     *
     * @param Collection $orders
     * @param array $data
     * @return void
     * @throws Throwable
     */
    protected function storePaymentMerge(Collection $orders, array $data): void
    {
        abort_if($orders->count() == 0, 400, __("order::messages.order_payment_not_allowed"));

        $user = auth()->user();

        $scale = Currency::subunit($orders[0]->currency);
        $factor = 10 ** $scale;

        $orderDueMinor = [];
        $totalDueMinor = 0;
        $orderSyncVersions = [];
        $waiterStatusFlowEnabled = (bool) setting('waiter_table_status_flow_enabled', true);

        /** @var Order $order */
        foreach ($orders as $order) {
            abort_if(
                ($waiterStatusFlowEnabled && $order->next_status != OrderStatus::Completed)
                || !$order->allowAddPayment(),
                400,
                __("order::messages.order_payment_not_allowed")
            );
            $order->syncApplicableOrderTaxes();
            $orderSyncVersions[$order->id] = $order->updated_at?->copy();
            $dueRounded = $order->due_amount->round($scale)->amount();
            $minor = (int)round($dueRounded * $factor);
            $orderDueMinor[$order->id] = $minor;
            $totalDueMinor += $minor;
        }

        abort_if($totalDueMinor <= 0, 400, __("order::messages.order_payment_not_allowed"));

        $amountToBePaid = 0;
        foreach ($data['payments'] as $p) {
            $amountToBePaid += ($p['amount'] ?? 0);
        }
        $amountToBePaid = round($amountToBePaid, $scale);

        $dueAmount = $totalDueMinor / $factor;

        abort_if(
            $amountToBePaid > $dueAmount,
            400,
            __("order::messages.payment_overpaid", [
                "paid" => number_format($amountToBePaid, 2),
                "total" => number_format($dueAmount, 2)
            ])
        );

        abort_if(
            $data['payment_mode'] == PaymentMode::Full->value && $amountToBePaid < $dueAmount,
            400,
            __("order::messages.insufficient_payment", [
                "paid" => number_format($amountToBePaid, 2),
                "total" => number_format($dueAmount, 2)
            ])
        );

        $session = PosSession::query()->findOrFail($data['session_id']);

        $ordersIds = $orders->pluck('id')->values()->all();
        $lastOrderId = end($ordersIds);

        $perOrderPayments = [];
        $orderPaidMinor = [];

        foreach ($orders as $order) {
            $perOrderPayments[$order->id] = [];
            $orderPaidMinor[$order->id] = 0;
        }

        foreach ($data['payments'] as $paymentIndex => $payment) {
            $paymentMinor = (int)round($payment['amount'] * $factor);
            $distributedMinor = 0;
            $gatewayPayment = $this->preparePaymentRow(
                order: $orders->first(),
                payment: [
                    "cashier_id" => $user->id,
                    "method" => $payment['method'],
                    "amount" => $paymentMinor / $factor,
                    "session" => $session,
                    "transaction_id" => $payment['transaction_id'] ?? null,
                ],
                index: $paymentIndex,
                scale: $scale,
                rawPayment: $payment,
            );

            foreach ($orders as $order) {
                if ($order->id === $lastOrderId) {
                    $share = $paymentMinor - $distributedMinor;
                } else {
                    $share = intdiv($paymentMinor * $orderDueMinor[$order->id], $totalDueMinor);
                    $distributedMinor += $share;
                }

                if ($share < 0) {
                    $share = 0;
                }

                $amount = $share / $factor;

                $perOrderPayments[$order->id][] = [
                    ...$gatewayPayment,
                    "amount" => $amount,
                    "session" => $session,
                ];

                $orderPaidMinor[$order->id] += $share;
            }
        }

        $merge = TableMerge::query()->findOrFail($orders->first()->table_merge_id);

        DB::transaction(function () use ($orders, $user, $merge, $data, $session, $perOrderPayments, $orderPaidMinor, $orderDueMinor, $orderSyncVersions, $waiterStatusFlowEnabled, $scale, $factor) {
            $lockedOrders = Order::query()
                ->whereIn('id', $orders->pluck('id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $orders = $orders->map(fn(Order $order) => $lockedOrders->get($order->id) ?: $order);

            /** @var Order $order */
            foreach ($orders as $order) {
                $this->authorization->authorize($order, $user);
                $syncedOrderUpdatedAt = $orderSyncVersions[$order->id] ?? null;
                if (!$syncedOrderUpdatedAt || !$order->updated_at?->equalTo($syncedOrderUpdatedAt)) {
                    $order->syncApplicableOrderTaxes();
                }
                $dueMinor = $orderDueMinor[$order->id];
                $paidMinor = $orderPaidMinor[$order->id];

                $currentDueMinor = (int)round(round($order->due_amount->amount(), $scale) * $factor);

                abort_if(
                    $currentDueMinor !== $dueMinor || !$order->allowAddPayment(),
                    409,
                    __("order::messages.order_payment_not_allowed")
                );

                $isOrderComplete = ($dueMinor === $paidMinor)
                    && ! $order->deliveryIsProviderManaged()
                    && ($order->next_status == OrderStatus::Completed || !$waiterStatusFlowEnabled);

                $order->storePayments($perOrderPayments[$order->id]);

                $order->update([
                    "status" => $isOrderComplete ? OrderStatus::Completed : $order->status,
                    "closed_at" => $isOrderComplete ? now() : null,
                    "cashier_id" => $user->id,
                    "pos_session_id" => $order->pos_session_id ?: $session->id
                ]);

                if ($isOrderComplete) {
                    event(
                        new OrderUpdateStatus(
                            order: $order,
                            status: OrderStatus::Completed,
                            changedById: $user->id,
                        )
                    );
                }
            }

            if ($orders->filter(fn($order) => $order->status != OrderStatus::Completed)->isEmpty()) {
                $query = Table::query()->where('current_merge_id', $merge->id);
                $tables = $query->get();
                $tableStatus = $waiterStatusFlowEnabled
                    ? TableStatus::Cleaning
                    : TableStatus::Available;
                $query->update(["current_merge_id" => null, "status" => $tableStatus]);
                $tables->each(fn(Table $table) => $table->storeStatusLog(status: $tableStatus));
                $merge->update(["closed_at" => now(), "closed_by" => $user->id]);
                event(new OrderMergeBillingPaid($merge));
            }

            $this->dispatchPrint($order, $data);
        });
    }

    /**
     * Verify/capture gateway-backed payments before any order lock is held.
     *
     * Cash/manual offline payments never pass a gateway key, so they continue to
     * store/queue independently of terminal or Razorpay availability.
     */
    private function preparePaymentRow(
        Order $order,
        array $payment,
        int $index,
        int $scale,
        array $rawPayment,
    ): array {
        $gatewayKey = $rawPayment['gateway'] ?? null;

        if (blank($gatewayKey)) {
            return $payment;
        }

        $gatewayData = (array) ($rawPayment['gateway_data'] ?? []);
        if (! empty($payment['transaction_id'])) {
            $gatewayData['transaction_id'] = $payment['transaction_id'];
        }

        try {
            $driver = app(PaymentGatewayManager::class)->driver((string) $gatewayKey);
        } catch (InvalidArgumentException) {
            $this->recordGatewayAttempt(
                order: $order,
                payment: $payment,
                rawPayment: $rawPayment,
                status: PaymentStatus::Failed,
                message: __('payment::payments.gateway_not_configured', [
                    'gateway' => $gatewayKey,
                ])
            );

            throw ValidationException::withMessages([
                "payments.{$index}.gateway" => __('payment::payments.gateway_not_configured', [
                    'gateway' => $gatewayKey,
                ]),
            ]);
        }

        try {
            $result = $driver->charge(new GatewayChargeRequest(
                amount: round((float) $payment['amount'], $scale),
                currency: $order->currency,
                orderReferenceNo: $order->reference_no,
                reference: ($payment['transaction_id'] ?? null) ?: (string) Str::uuid(),
                method: (string) $payment['method'],
                terminalId: $gatewayData['terminal_id'] ?? null,
                metadata: $gatewayData,
            ));
        } catch (Throwable $exception) {
            report($exception);
            $this->recordGatewayAttempt(
                order: $order,
                payment: $payment,
                rawPayment: $rawPayment,
                status: PaymentStatus::Failed,
                message: $exception->getMessage()
            );

            throw ValidationException::withMessages([
                "payments.{$index}.gateway" => __('payment::payments.gateway_declined'),
            ]);
        }

        if (! $result->isApproved()) {
            $status = $result->isPending()
                ? PaymentStatus::Pending
                : PaymentStatus::Failed;

            $this->recordGatewayAttempt(
                order: $order,
                payment: $payment,
                rawPayment: $rawPayment,
                status: $status,
                result: $result,
                message: $result->message ?: __('payment::payments.gateway_declined')
            );

            throw ValidationException::withMessages([
                "payments.{$index}.gateway" => $result->message
                    ?: __('payment::payments.gateway_declined'),
            ]);
        }

        $meta = array_merge((array) ($payment['meta'] ?? []), array_filter([
            'approval_code' => $result->approvalCode,
            'card_last4' => $result->cardLast4,
            'card_scheme' => $result->cardScheme,
        ]));

        return [
            ...$payment,
            'transaction_id' => $result->gatewayTransactionId ?? ($payment['transaction_id'] ?? null),
            'gateway' => (string) $gatewayKey,
            'gateway_transaction_id' => $result->gatewayTransactionId,
            'gateway_response' => empty($result->raw) ? null : $result->raw,
            'processed_at' => now(),
            'status' => PaymentStatus::Completed->value,
            'meta' => empty($meta) ? null : $meta,
        ];
    }

    private function recordGatewayAttempt(
        Order $order,
        array $payment,
        array $rawPayment,
        PaymentStatus $status,
        ?GatewayChargeResult $result = null,
        ?string $message = null,
    ): void {
        $gatewayKey = $rawPayment['gateway'] ?? null;
        if (blank($gatewayKey) || ! $this->paymentStatusColumnExists()) {
            return;
        }

        $gatewayResponse = $result?->raw ?? [];
        if ($message) {
            $gatewayResponse = [
                ...$gatewayResponse,
                'message' => $message,
            ];
        }

        $attributes = [
            'order_reference_no' => $order->reference_no,
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'cashier_id' => $payment['cashier_id'] ?? auth()->id(),
            'received_by' => $payment['received_by'] ?? auth()->id(),
            'transaction_id' => $result?->gatewayTransactionId
                ?? ($payment['transaction_id'] ?? data_get($rawPayment, 'gateway_data.payment_id')),
            'method' => $payment['method'],
            'amount' => $payment['amount'],
            'type' => PaymentType::Payment->value,
            'currency' => $order->currency,
            'currency_rate' => $order->currency_rate,
            'received_at' => now(),
            'gateway' => (string) $gatewayKey,
            'gateway_transaction_id' => $result?->gatewayTransactionId,
            'gateway_response' => empty($gatewayResponse) ? null : $gatewayResponse,
            'processed_at' => now(),
            'meta' => $payment['meta'] ?? null,
        ];

        if ($this->paymentStatusColumnExists()) {
            $attributes['status'] = $status->value;
        }

        Payment::query()->create($attributes);
    }

    private function paymentStatusColumnExists(): bool
    {
        static $exists = null;

        return $exists ??= Schema::hasColumn('payments', 'status');
    }

    /**
     * Dispatch print
     *
     * @param Order $order
     * @param array $data
     * @return void
     * @throws Throwable
     */
    private function dispatchPrint(Order $order, array $data): void
    {
        $withPrint = $data['with_print'] ?? false;
        if ($withPrint) {
            $printType = $data['payment_mode'] == PaymentMode::Full->value
                ? PrintContentType::Invoice
                : PrintContentType::Bill;

            $this->queuePrint($order, $printType, $data['register_id']);

            if ((bool) setting('kitchen_print_with_payment_enabled', false)
                && !Cache::pull("orders:{$order->id}:skip_payment_kitchen_print", false)) {
                $this->queuePrint($order, PrintContentType::Kitchen);
            }
        }
    }

    private function queuePrint(Order $order, PrintContentType $type, ?int $specificId = null): void
    {
        DispatchPrintJob::dispatchAfterCommit($order->id, $type, $specificId);
    }

    /** @inheritDoc */
    public function getPaymentMeta(int|string $id): array
    {
        $query = app(OrderServiceInterface::class)->getModel()
            ->query()
            ->select("id", "branch_id", "table_merge_id", "waiter_id", "created_by", "type", "currency", "currency_rate", "subtotal", "total", "due_amount", "scheduled_at")
            ->withCount(["products" => fn($q) => $q->whereNotIn("status", [OrderProductStatus::Cancelled, OrderProductStatus::Refunded])])
            ->whereNot("payment_status", OrderPaymentStatus::Paid)
            ->activeOrders()
            ->with(["branch", "taxes", "discount"]);

        /** @var Order|null $order */
        $order = null;
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $order = (clone $query)->whereKey((int) $id)->first();
        }

        /** @var Order $order */
        $order ??= (clone $query)->where('reference_no', (string) $id)->firstOrFail();

        $orders = collect();
        if (!is_null($order->table_merge_id)) {
            $orders = (clone $query)
                ->where("table_merge_id", $order->table_merge_id)
                ->where("id", "!=", $order->id)
                ->get();
        }

        $orders->push($order);
        $this->authorization->authorizeMany($orders, auth()->user());
        $orders->each(function (Order $orderRow) {
            $orderRow->syncApplicableOrderTaxes();
            $orderRow->loadMissing(["branch", "taxes", "discount"]);
        });

        $productsCount = 0;
        $subtotal = new Money(0, $order->currency);
        $grandTotal = new Money(0, $order->currency);
        $totalTax = new Money(0, $order->currency);
        $totalDiscount = new Money(0, $order->currency);
        $dueAmount = new Money(0, $order->currency);
        $totalPaid = new Money(0, $order->currency);

        /** @var Order $orderRow */
        foreach ($orders as $orderRow) {

            $productsCount += $orderRow->products_count;

            $subtotal = $subtotal->add($orderRow->subtotal);
            $grandTotal = $grandTotal->add($orderRow->total);
            $totalTax = $totalTax->add($orderRow->totalTax());

            if (!is_null($orderRow->discount)) {
                $totalDiscount = $totalDiscount->add($orderRow->discount->amount);
            }

            $dueAmount = $dueAmount->add($orderRow->due_amount);

            $totalPaid = $totalPaid->add($orderRow->total->subtract($orderRow->due_amount));
        }

        $activeRegisterIds = PosRegister::query()
            ->where('branch_id', $order->branch_id)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        return [
            // The Sales order list can collect an offline payment without a
            // POS viewer context, but the payment still belongs to an open
            // register session in the order's own branch.
            "open_registers" => PosRegister::list($order->branch_id, true)
                ->filter(fn (array $register) => ! empty($register['session']['id'])
                    && in_array($register['id'], $activeRegisterIds))
                ->values(),
            "payment_methods" => array_values(array_filter(
                PaymentMethod::toArrayTrans(),
                fn($orderType) => in_array($orderType['id'], $order->branch->payment_methods ?: [])
            )),
            "payment_modes" => PaymentMode::toArrayTrans(),
            "payment_gateways" => app(PaymentGatewayManager::class)->available(),
            "order" => [
                "total_products" => $productsCount,
                "sub_total" => $subtotal->round(),
                "grand_total" => $grandTotal->round(),
                "total_tax" => $totalTax->round(),
                "discount" => $totalDiscount->round(),
                "due_amount" => $dueAmount->round(),
                "total_paid" => $totalPaid->round(),
                "currency" => $order->currency,
                "precision" => Currency::subunit($order->currency)
            ]
        ];
    }
}
