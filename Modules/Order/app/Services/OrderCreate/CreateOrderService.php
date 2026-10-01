<?php

namespace Modules\Order\Services\OrderCreate;

use DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Currency\Currency;
use Modules\Currency\Models\CurrencyRate;
use Modules\Option\Models\Option;
use Modules\Option\Models\OptionValue;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Models\Order;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Models\PosSession;
use Modules\Product\Models\Product;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Modules\SeatingPlan\Enums\TableMergeType;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Table;
use Modules\Tax\Models\Tax;
use Modules\Tax\Services\TaxCalculationService;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class CreateOrderService implements CreateOrderServiceInterface
{
    public function __construct(
        private readonly EffectiveTenantEntitlementService $entitlements,
    ) {
    }
    /** @inheritDoc */
    public function create(array $data): Order
    {
        return $this->createForActor($data, auth()->user());
    }

    public function createForActor(array $data, User $user, bool $trustedServerCharges = false): Order
    {
        return DB::transaction(function () use ($data, $user, $trustedServerCharges) {

            /** @var Branch $branch */
            $branch = isset($data['branch_id'])
                ? Branch::query()->withoutGlobalScopes()
                    ->whereKey((int) $data['branch_id'])
                    ->where('tenant_id', (int) $user->tenant_id)
                    ->firstOrFail()
                : ($user->assignedToBranch()
                    ? Branch::findOrFail($user->branch_id)
                    : Branch::main()->firstOrFail());


            $orderType = OrderType::from($data['type']);
            if ($orderType === OrderType::Delivery) {
                $tenant = Tenant::query()->withoutGlobalScopes()
                    ->whereKey($branch->tenant_id)->where('is_active', true)->first();
                abort_unless($tenant && $this->entitlements->has($tenant, 'delivery'), 403,
                    'Delivery is not included in this restaurant subscription.');
                abort_unless(collect($branch->order_types)->contains(
                    fn ($type) => ($type instanceof OrderType ? $type->value : $type) === OrderType::Delivery->value
                ), 422, 'Delivery is not enabled for this restaurant branch.');
            }

            $this->validateTableCapacity($data, $branch);

            $isDineIn = $orderType === OrderType::DineIn;

            $data['payment_methods'] = $isDineIn ? [] : ($data['payment_methods'] ?? []);
            $data['payments'] = $isDineIn ? [] : ($data['payments'] ?? []);

            $posSession = $this->getPosActiveSession(
                branch: $branch,
                user: $user,
                isDineIn: $isDineIn,
                posRegisterId: isset($data['pos_register_id']) ? (int) $data['pos_register_id'] : null,
                paymentMethods: $data['payment_methods']
            );

            $data['pos_session_id'] = $posSession?->id;
            $data['currency_rate'] = CurrencyRate::for($branch->currency);
            [$products, $subtotal] = $this->getProducts($data['products'], $orderType, $branch, $data['currency_rate']);

            $additionalPayments = collect($data['additional_payments'] ?? []);
            foreach ($additionalPayments as $label => $amount) {
                if (! is_string($label) || ! preg_match('/^[A-Za-z][A-Za-z0-9 _-]{1,60}$/', $label)) {
                    throw ValidationException::withMessages(['additional_payment' => ['Charge names may contain letters, numbers, spaces, underscores and hyphens.']]);
                }
                if (! is_numeric($amount) || ! is_finite((float) $amount) || (float) $amount < 0) {
                    throw ValidationException::withMessages(['amount' => ['Charges must be valid non-negative amounts.']]);
                }
            }
            $additionalAmount = round((float) $additionalPayments->sum(), Currency::subunit($branch->currency));
            $trustedDeliveryCharge = $trustedServerCharges
                && $orderType === OrderType::Delivery
                && $additionalPayments->keys()->all() === ['customer_delivery_fee']
                && data_get($data, 'fulfilment.source') === 'whatsapp'
                && (float) data_get($data, 'fulfilment.customer_delivery_fee', -1) === (float) $additionalPayments->get('customer_delivery_fee');
            if ($additionalAmount < 0 || (! $trustedDeliveryCharge && $additionalAmount > round($subtotal, Currency::subunit($branch->currency)))) {
                throw ValidationException::withMessages([
                    'amount' => ['The delivery or service charge must be between 0 and the server-calculated item subtotal.'],
                ]);
            }

            [$taxes, $totalTaxes] = $this->getTaxesApplicable($orderType, $branch, $subtotal, $data['currency_rate']);

            $data['subtotal'] = $subtotal;
            $data['total'] = $subtotal + $totalTaxes + $additionalAmount;

            $isUpdate = isset($data['id']);
            if ($isUpdate) {
                $order = Order::with('products.options.values')->findOrFail($data['id']);
                $order->update([
                    "subtotal"      => $data['subtotal'],
                    "total"         => $data['total'],
                    "guest_count"   => $data['guest_count'] ?? $order->guest_count,
                    "notes"         => $data['notes'] ?? $order->notes,
                    "status"        => $data['status'] ?? $order->status,
                    "payment_status"=> $isDineIn
                        ? $order->payment_status
                        : (empty($data['payment_methods'])
                            ? OrderPaymentStatus::Unpaid
                            : OrderPaymentStatus::Paid),
                ]);
            } else {
                $order = $this->store($branch, $user, $data);
            }

            $this->updateTableStatus($order);
            if (!$isDineIn) {
                $this->storePayments($user, $order, $posSession, $data['payment_methods'], $data['payments']);
            }

            if ($isUpdate) {
                $this->storeOrUpdateOrderProducts($order, $products);
                $this->replaceOrderTaxes($order, $taxes);
            } else {
                $this->storeOrderProducts($order, $products);
                $this->replaceOrderTaxes($order, $taxes);
            }

            event(new OrderCreated($order, (bool) ($data['kitchen_display'] ?? true)));

            return $order;
        });
    }


    /**
     * Get pos active session
     *
     * @param Branch $branch
     * @param User $user
     * @param bool $isDineIn
     * @param int|null $posRegisterId
     * @param array $paymentMethods
     * @return PosSession|null
     */
    public function getPosActiveSession(
        Branch $branch,
        User   $user,
        bool   $isDineIn,
        ?int   $posRegisterId,
        array  $paymentMethods
    ): ?PosSession {
        if (
            !$isDineIn
            && $user->can('admin.orders.receive_payment')
            && !empty($paymentMethods)
        ) {
            $posRegister = PosRegister::query()
                ->with(["lastSession" => fn($query) => $query->with("branch:id,currency")
                    ->where('status', PosSessionStatus::Open)])
                ->where('id', $posRegisterId)
                ->where("branch_id", $branch->id)
                ->withOutGlobalBranchPermission()
                ->firstOrFail();

            abort_if(
                is_null($posRegister->lastSession),
                400,
                __("pos::messages.no_active_session", [
                    "action" => __("pos::messages.cash_payment")
                ])
            );
            return $posRegister->lastSession;
        }
        return null;
    }

    /**
     * Get products with options, prices, and taxes
     *
     * @param array $items
     * @param OrderType $orderType
     * @param Branch $branch
     * @param float $currencyRate
     * @return array
     */
    private function getProducts(array $items, OrderType $orderType, Branch $branch, float $currencyRate): array
    {
        $subtotal = 0;
        $items = collect($items); // ❗ DO NOT keyBy

        $data = [];

        // Fetch products once
        $productModels = Product::query()
            // Partner/service actors do not necessarily carry the same
            // branch session as an interactive POS user. Remove implicit
            // scopes, then restore the security boundary explicitly through
            // the already-authorised branch and its tenant. This keeps the
            // catalog response and order creation on the same product set.
            ->withoutGlobalScopes()
            ->with(['options', 'taxes'])
            ->whereIn('id', $items->pluck('id')->unique())
            ->where('is_active', true)
            ->where('is_available', true)
            ->whereHas('branch', fn($query) => $query->withoutGlobalScopes()
                ->where('branches.id', $branch->id)
                ->where('branches.tenant_id', $branch->tenant_id))
            ->get()
            ->keyBy('id');

        foreach ($items as $cartItem) {

            /** @var Product $product */
            $product = $productModels->get($cartItem['id']);
            if (! $product) {
                throw ValidationException::withMessages([
                    'products' => ["Product {$cartItem['id']} is unavailable at the selected branch."],
                ]);
            }
            $qty = (int) $cartItem['quantity'];
            $unitPrice = $product->selling_price->amount();

            $options = [];
            $taxes = [];

            foreach (($cartItem['options'] ?? []) as $productOption) {

                /** @var Option $option */
                $option = $product->options->firstWhere('id', $productOption['id']);
                if (! $option) {
                    throw ValidationException::withMessages([
                        'products' => ["An option selected for {$product->name} is no longer available."],
                    ]);
                }
                $optionData = ['id' => $option->id, 'value' => null, 'values' => collect()];

                foreach ($productOption['values'] as $productOptionValue) {

                    /** @var OptionValue $value */
                    $value = $option->values->firstWhere('id', $productOptionValue['id']);
                    if (! $value) {
                        throw ValidationException::withMessages([
                            'products' => ["An option value selected for {$product->name} is no longer available."],
                        ]);
                    }

                    if ($option->type->isFieldType()) {
                        $optionData['value'] = $productOptionValue['value'] ?: $value->label;
                    }

                    $optionData['values']->push($value);

                    if ($value->price) {
                        if ($value->price_type->isPercent()) {
                            $unitPrice += ($product->selling_price->amount() * $value->price) / 100;
                        } else {
                            $unitPrice += $value->price->amount();
                        }
                    }
                }

                $options[] = $optionData;
            }

            $lineGrossSubtotal = $unitPrice * $qty;
            $applicableTaxes = $this->filterTaxesForOrderType($product->taxes, $orderType);
            $calculatedTaxes = app(TaxCalculationService::class)->calculate($lineGrossSubtotal, $applicableTaxes);
            $lineSubtotal = app(TaxCalculationService::class)->taxableAmount($lineGrossSubtotal, $applicableTaxes);
            $lineTaxTotal = $calculatedTaxes->sum('amount');
            $taxes = $this->formatCalculatedTaxes($calculatedTaxes, $branch, $currencyRate);
            $total = $lineSubtotal + $lineTaxTotal;

            $data[] = [
                'id' => $product->id,
                'order_product_id' => $cartItem['order_product_id'] ?? null,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'subtotal' => $lineSubtotal,
                'tax_total' => $lineTaxTotal,
                'total' => $total,
                'taxes' => $taxes,
                'options' => $options
            ];

            $subtotal += $total;
        }

        return [$data, $subtotal];
    }


    /**
     * Get taxes applicable
     *
     * @param OrderType $orderType
     * @param Branch $branch
     * @param float $subtotal
     * @param float $currencyRate
     * @return array
     */
    public function getTaxesApplicable(OrderType $orderType, Branch $branch, float $subtotal, float $currencyRate): array
    {
        $taxRows = Tax::list($branch->id, true)
            ->filter(
                fn($tax) => empty($tax['order_types']) || in_array($orderType->value, $tax['order_types'])
            )
            ->values();

        $taxes = $this->taxModelsFromListRows($taxRows);
        $calculatedTaxes = app(TaxCalculationService::class)->calculate($subtotal, $taxes);
        $data = $this->formatCalculatedTaxes($calculatedTaxes, $branch, $currencyRate);
        $totalTaxes = $calculatedTaxes
            ->where('additive', true)
            ->sum('amount');

        return [$data, $totalTaxes];
    }

    private function filterTaxesForOrderType(Collection $taxes, OrderType $orderType): Collection
    {
        return $taxes
            ->filter(function (Tax $tax) use ($orderType) {
                $orderTypes = $tax->order_types ?? [];

                return empty($orderTypes) || in_array($orderType->value, $orderTypes, true);
            })
            ->values();
    }

    private function taxModelsFromListRows(Collection $taxRows): Collection
    {
        $taxIds = $taxRows->pluck('id')->filter()->values();

        if ($taxIds->isEmpty()) {
            return collect();
        }

        $taxModels = Tax::query()
            ->withOutGlobalBranchPermission()
            ->whereIn('id', $taxIds)
            ->get()
            ->keyBy('id');

        return $taxRows
            ->map(fn(array $taxRow) => $taxModels->get($taxRow['id']))
            ->filter()
            ->values();
    }

    private function formatCalculatedTaxes(Collection $calculatedTaxes, Branch $branch, float $currencyRate): array
    {
        return $calculatedTaxes
            ->map(function (array $row) use ($branch, $currencyRate) {
                /** @var Tax $tax */
                $tax = $row['tax'];

                return [
                    'id' => $tax->id,
                    'name' => $tax->getTranslations('name') ?: ['en' => $tax->name],
                    'rate' => $tax->rate,
                    'type' => $tax->type->value,
                    'compound' => $tax->compound,
                    'currency' => $branch->currency,
                    'currency_rate' => $currencyRate,
                    'amount' => $row['amount'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Store order
     *
     * @param Branch $branch
     * @param User $user
     * @param array $data
     * @return Order
     */
    private function store(Branch $branch, User $user, array $data): Order
    {

        $isDineIn = $data['type'] == OrderType::DineIn->value;
        $tableMergeId = null;
        $hasPayments = !$isDineIn
            && $user->can('admin.orders.receive_payment')
            && isset($data['payment_methods'])
            && !empty($data['payment_methods']);

        $tableId = $isDineIn ? ($data['table_id'] ?? null) : null;
        $waiterId = $user->hasRole(DefaultRole::Waiter->value) && $isDineIn
            ? $user->id
            : null;

        if (!is_null($tableId)) {

            $table = Table::query()
                ->with(['currentMerge', 'activeOrders'])
                ->lockForUpdate()
                ->findOrFail($tableId);

            // ✅ CAPACITY GUARD (MULTI-ORDER SAFE)
            $existingGuests = $table->activeOrders
                ->sum(fn($order) => (int) ($order->guest_count ?? 1));

            $newGuests = (int) ($data['guest_count'] ?? 1);

            abort_if(
                ($existingGuests + $newGuests) > $table->capacity,
                400,
                __("seatingplan::tables.capacity_exceeded")
            );

            // waiter auto assign
            if (is_null($waiterId) && !is_null($table->assigned_waiter_id)) {
                $waiterId = $table->assigned_waiter_id;
            }

            // billing merge case
            if (
                !is_null($table->currentMerge)
                && $table->currentMerge->type == TableMergeType::Billing
            ) {
                $tableMergeId = $table->currentMerge->id;
            }
        }

        return Order::query()
            ->create([
                "branch_id" => $branch->id,
                "table_id" => $tableId,
                "pos_register_id" => $data['pos_register_id'] ?? null,
                "pos_session_id" => $hasPayments ? ($data['pos_session_id'] ?? null) : null,
                "customer_id" => $data['customer_id'],
                "waiter_id" => $waiterId,
                "cashier_id" => $hasPayments
                    ? $user->id
                    : null,
                "status" => (isset($data['status']) && in_array($data['status'], ['pending'], true)) ? OrderStatus::Pending : OrderStatus::Confirmed,
                "type" => $data['type'],
                "payment_status" => $hasPayments
                    ? OrderPaymentStatus::Paid
                    : OrderPaymentStatus::Unpaid,
                "payment_at" => $hasPayments ? now() : null,
                "currency" => $branch->currency,
                "currency_rate" => $data['currency_rate'],
                "subtotal" => $data['subtotal'],
                "total" => $data['total'],
                "due_amount" => $hasPayments ? 0 : $data['total'],
                "guest_count" => $data['guest_count'] ?? 1,
                "notes" => $data["notes"] ?? null,
                "fulfilment" => array_filter([
                    ...($data['fulfilment'] ?? []),
                    'source' => $data['fulfilment']['source'] ?? ($waiterId && (int) $waiterId === (int) $user->id ? 'waiter_app' : 'admin'),
                ], static fn ($value) => $value !== null && $value !== ''),
                "order_date" => now(),
                "served_at" => $isDineIn ? now() : null,
                "kitchen_display" => (bool) ($data['kitchen_display'] ?? true),
                "table_merge_id" => $tableMergeId
            ]);
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
    public function storePayments(
        User            $user,
        Order           $order,
        PosSession|null $posSession,
        array           $paymentMethods,
        array           $payments,
    ): void {
        if ($user->can('admin.orders.receive_payment') && !empty($paymentMethods)) {
            $total = $order->total->amount();
            $data = collect();

            if (count($paymentMethods) == 1) {
                $payments = [
                    [
                        "method" => $paymentMethods[0],
                        "amount" => $order->total->amount(),
                    ]
                ];
            }

            foreach ($payments as $payment) {
                $data->push([
                    "cashier_id" => $user->id,
                    "method" => $payment['method'],
                    "amount" => $payment['amount'],
                    "session" => $posSession
                ]);
            }

            $paymentTotal = $data->sum('amount');

            abort_if(
                round($paymentTotal, 2) < round($total, 2),
                400,
                __("order::messages.insufficient_payment", [
                    "paid" => number_format($paymentTotal, 2),
                    "total" => number_format($total, 2)
                ])
            );

            $order->storePayments($data->all());
        }
    }

    /**
     * Store order products
     *
     * @param Order $order
     * @param array $products
     * @return void
     */
    private function storeOrUpdateOrderProducts(Order $order, array $products): void
    {
        $order->load('products');
        foreach ($products as $product) {
            $orderProductId = $product['order_product_id'] ?? null;
            /**
             * 🔵 CASE 1: Update existing order product
             */
            if ($orderProductId) {

                $existing = $order->products->firstWhere('id', $orderProductId);

                // If not found OR already sent → create new
                if (!$existing) {
                    $this->storeOrderProduct($order, $product);
                    continue;
                }

                // ✅ Update quantity only
                $existing->update([
                    'quantity'  => $product['quantity'],
                    'subtotal'  => $product['subtotal'],
                    'tax_total' => $product['tax_total'],
                    'total'     => $product['total'],
                ]);
                $this->replaceOrderProductTaxes($existing, $product['taxes']);
                continue;
            }

            /**
             * 🟢 CASE 2: New product → always create
             */
            $this->storeOrderProduct($order, $product);
        }
    }
    /** * Store order products * 
     * * @param Order $order * 
     * @param array $products * 
     * @return void */
    private function storeOrderProducts(Order $order, array $products): void
    {
        foreach ($products as $product) {
            $this->storeOrderProduct($order, $product);
        }
    }

    private function storeOrderProduct(Order $order, array $product): void
    {
        $orderProduct = $order->products()->create([
            'product_id' => $product['id'],
            'currency' => $order->currency,
            'currency_rate' => $order->currency_rate,
            'quantity' => $product['quantity'],
            'unit_price' => $product['unit_price'],
            'subtotal' => $product['subtotal'],
            'tax_total' => $product['tax_total'],
            'total' => $product['total'],
        ]);

        $this->replaceOrderProductTaxes($orderProduct, $product['taxes']);
    }

    private function replaceOrderProductTaxes($orderProduct, array $taxes): void
    {
        $orderProduct->taxes()->delete();

        foreach ($taxes as $tax) {
            $orderProduct->taxes()->create([
                'order_id' => $orderProduct->order_id,
                'tax_id' => $tax['id'],
                'name' => $tax['name'],
                'rate' => $tax['rate'],
                'currency' => $tax['currency'],
                'currency_rate' => $tax['currency_rate'],
                'amount' => $tax['amount'],
                'type' => $tax['type'],
                'compound' => $tax['compound'],
            ]);
        }
    }

    private function replaceOrderTaxes(Order $order, array $taxes): void
    {
        $order->taxes()->delete();

        foreach ($taxes as $tax) {
            $order->taxes()->create([
                'tax_id' => $tax['id'],
                'name' => $tax['name'],
                'rate' => $tax['rate'],
                'currency' => $tax['currency'],
                'currency_rate' => $tax['currency_rate'],
                'amount' => $tax['amount'],
                'type' => $tax['type'],
                'compound' => $tax['compound'],
            ]);
        }
    }
    /**
 * Validate table guest capacity (CREATE + UPDATE SAFE)
 */
private function validateTableCapacity(array $data, Branch $branch): void
{
    if (($data['type'] ?? null) != OrderType::DineIn->value) {
        return;
    }

    $tableId = $data['table_id'] ?? null;
    if (!$tableId) {
        return;
    }

    $table = Table::query()
        ->with('activeOrders:id,table_id,guest_count')
        ->lockForUpdate()
        ->findOrFail($tableId);

    $newGuests = (int) ($data['guest_count'] ?? 1);

    // Update case → current order guests exclude
    $existingGuests = $table->activeOrders
        ->when(
            isset($data['id']),
            fn ($q) => $q->where('id', '!=', $data['id'])
        )
        ->sum(fn ($order) => (int) ($order->guest_count ?? 1));

    abort_if(
        ($existingGuests + $newGuests) > $table->capacity,
        400,
        __("seatingplan::tables.capacity_exceeded")
    );
}

}
