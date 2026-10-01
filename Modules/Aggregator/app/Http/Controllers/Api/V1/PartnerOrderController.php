<?php

namespace Modules\Aggregator\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Aggregator\Models\PartnerApiOrderMapping;
use Modules\Aggregator\Services\PartnerApi\PartnerResourceService;
use Modules\Aggregator\Support\PartnerApiResponse;
use Modules\Aggregator\Support\PartnerContext;
use Modules\Branch\Models\Branch;
use Modules\Menu\Models\OnlineMenu;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Delivery\CustomerDeliveryQuote;
use Modules\Order\Delivery\DeliveryReadModel;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDelivery;
use Modules\Order\Services\OrderCreate\CreateOrderService;
use Modules\Order\Support\CustomerTrackingToken;
use Modules\Order\Support\OrderFeedbackAccess;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Services\Payment\PaymentServiceInterface;
use Modules\Product\Models\Product;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class PartnerOrderController
{
    public function __construct(
        private readonly PartnerResourceService $resources,
        private readonly CreateOrderService $orders,
        private readonly EffectiveTenantEntitlementService $entitlements,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $context = $this->context($request);
        $perPage = min(100, max(1, $request->integer('per_page', 25)));
        $mappings = PartnerApiOrderMapping::query()
            ->where('partner_id', $context->partner->id)
            ->where('tenant_id', $context->tenantId())
            ->whereHas('order', fn ($query) => $this->scopeOrderToMappingTenant($query, $context))
            ->with('order.products.product')
            ->latest()->paginate($perPage);

        return PartnerApiResponse::success(
            $mappings->getCollection()->map(fn ($mapping) => $this->orderData($mapping))->values(),
            200,
            ['page' => $mappings->currentPage(), 'per_page' => $mappings->perPage(), 'total' => $mappings->total(), 'last_page' => $mappings->lastPage()],
        );
    }

    public function show(Request $request, string $orderId): JsonResponse
    {
        $mapping = $this->mapping($this->context($request), $orderId);
        $this->auditBranch($request, $mapping);

        return $mapping
            ? PartnerApiResponse::success($this->orderData($mapping->load('order.products.product')))
            : PartnerApiResponse::error('ORDER_NOT_FOUND', 'Order was not found.', 404);
    }

    public function store(Request $request): JsonResponse
    {
        $context = $this->context($request);
        if (! $request->hasHeader('Idempotency-Key') || ! preg_match('/^[A-Za-z0-9._:-]{8,160}$/', (string) $request->header('Idempotency-Key'))) {
            return PartnerApiResponse::error('IDEMPOTENCY_KEY_REQUIRED', 'A stable Idempotency-Key (8-160 safe characters) is required.', 400);
        }
        if ($unknown = $this->unknownOrderFields($request->all())) {
            return PartnerApiResponse::error(
                'UNSUPPORTED_FIELDS',
                'Send only documented order fields. Product prices, taxes, totals and payment state are calculated by NexDine; amount is the optional delivery or service charge.',
                422,
                ['fields' => $unknown],
            );
        }
        $data = $request->validate([
            'external_order_id' => ['required', 'string', 'max:160'],
            'branch_id' => ['required', 'uuid'],
            'order_type' => ['required', Rule::in([OrderType::Takeaway->value, OrderType::Pickup->value, OrderType::Delivery->value, OrderType::SelfService->value])],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:120'],
            'customer.phone' => ['required', 'string', 'max:30'],
            'customer.email' => ['nullable', 'email', 'max:190'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'additional_payment' => ['sometimes', 'required', 'array', 'max:10'],
            'additional_payment.*' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'delivery_address' => ['required_if:order_type,delivery', 'nullable', 'array'],
            'delivery_address.address_line1' => ['required_if:order_type,delivery', 'string', 'max:300'],
            'delivery_address.city' => ['required_if:order_type,delivery', 'string', 'max:120'],
            'delivery_address.landmark' => ['nullable', 'string', 'max:200'],
            'delivery_address.latitude' => ['required_if:order_type,delivery', 'numeric', 'between:-90,90'],
            'delivery_address.longitude' => ['required_if:order_type,delivery', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $branchId = $this->resources->internalId($context, 'branch', $data['branch_id']);
        $branch = $branchId ? Branch::query()->withoutGlobalScopes()->whereKey($branchId)->where('tenant_id', $context->tenantId())->whereNull('deleted_at')->where('is_active', true)->first() : null;
        if (! $branch || ! $context->canAccessBranch((int) $branch->id)) {
            return PartnerApiResponse::error('BRANCH_NOT_FOUND', 'Branch was not found or is outside this credential scope.', 404);
        }
        $request->attributes->set('partner_branch_id', (int) $branch->id);

        $products = [];
        $deliverySubtotal = 0.0;
        foreach ($data['items'] as $item) {
            $productId = $this->resources->internalId($context, 'product', $item['product_id']);
            $product = $productId ? Product::query()->withoutGlobalScopes()
                ->whereKey($productId)->whereNull('deleted_at')->where('is_active', true)->where('is_available', true)
                ->whereHas('branch', fn ($query) => $query->where('branches.id', $branch->id))->first() : null;
            if (! $product) {
                return PartnerApiResponse::error('PRODUCT_NOT_FOUND', 'One or more products are unavailable for this branch.', 422);
            }
            $products[] = ['id' => $productId, 'quantity' => $item['quantity'], 'options' => []];
            $deliverySubtotal += (float) $product->selling_price->amount() * (int) $item['quantity'];
        }

        $deliveryQuote = null;
        if ($data['order_type'] === OrderType::Delivery->value) {
            app(\Modules\Order\Delivery\DeliveryAvailability::class)->assertAvailable($branch);
            $tenant = $request->attributes->get('tenant');
            if (! $tenant instanceof Tenant || ! $this->entitlements->has($tenant, 'delivery')) {
                return PartnerApiResponse::error('DELIVERY_SERVICE_NOT_AVAILABLE',
                    'Third-party delivery is not active for this restaurant.', 403);
            }
            if (isset($data['additional_payment'])) {
                return PartnerApiResponse::error('DELIVERY_FEE_SERVER_CONTROLLED',
                    'Do not send delivery charges. NexDine calculates the delivery fee from the confirmed location.', 422);
            }
            $calculator = app(CustomerDeliveryQuote::class);
            $deliveryQuote = $calculator->calculate($branch, (array) $data['delivery_address'], $deliverySubtotal,
                (bool) setting('delivery_enabled', false) ? $calculator->settings()
                    : ['delivery_pricing_method' => 'base_plus_km', 'base_delivery_charge' => 0]);
            if (! $deliveryQuote['serviceable']
                && $deliveryQuote['failure_code'] === 'OUTSIDE_DELIVERY_RADIUS'
                && setting('partner_api_delivery_radius_policy', 'enforce') === 'bypass') {
                $deliveryQuote = [
                    ...$deliveryQuote,
                    'serviceable' => true,
                    'failure_code' => null,
                    'message' => null,
                    'pricing_rule' => 'partner_api_radius_bypass',
                ];
            }
            if (! $deliveryQuote['serviceable']) {
                return PartnerApiResponse::error((string) $deliveryQuote['failure_code'], (string) $deliveryQuote['message'], 422);
            }
        }

        $existing = $this->mappingByExternalId($context, $data['external_order_id']);
        if ($existing?->order_id) {
            return PartnerApiResponse::success($this->orderData($existing->load('order.products.product')), 200, ['idempotent_replay' => true]);
        }

        // CreateOrderService intentionally honours the authenticated actor's
        // branch scopes. Never pick an arbitrary operator from another branch:
        // that can hide an already validated product and turn a valid partner
        // order into an undefined-index HTTP 500.
        $actor = $this->tenantActor($context, (int) $branch->id);
        if (! $actor) {
            return PartnerApiResponse::error('TENANT_USER_MISSING', 'No active tenant operator is available.', 409);
        }

        $previousUser = Auth::user();
        try {
            Auth::setUser($actor);
            $mapping = DB::transaction(function () use ($context, $data, $branch, $products, $deliveryQuote) {
                $mapping = PartnerApiOrderMapping::query()->firstOrCreate(
                    ['partner_id' => $context->partner->id, 'external_order_id' => $data['external_order_id']],
                    ['uuid' => (string) Str::uuid(), 'tenant_id' => $context->tenantId(), 'credential_id' => $context->credential->id, 'branch_id' => $branch->id, 'status' => 'processing'],
                );
                abort_unless(
                    (int) $mapping->tenant_id === $context->tenantId()
                    && (int) $mapping->branch_id === (int) $branch->id,
                    409,
                    'The external order reference conflicts with an existing order.',
                );
                if ($mapping->order_id) {
                    abort_unless(
                        Order::query()->withoutGlobalScopes()
                            ->whereKey($mapping->order_id)
                            ->where('branch_id', $mapping->branch_id)
                            ->whereHas('branch', fn ($query) => $query->withoutGlobalScopes()->where('tenant_id', $context->tenantId()))
                            ->exists(),
                        409,
                        'The external order reference conflicts with an existing order.',
                    );

                    return $mapping;
                }
                $customer = $this->partnerCustomer($context, $branch, $data['customer']);
                $deliveryFee = (float) ($deliveryQuote['delivery_fee'] ?? 0);
                $additionalPayments = $deliveryQuote !== null
                    ? ($deliveryFee > 0 ? ['customer_delivery_fee' => $deliveryFee] : [])
                    : ($data['additional_payment'] ?? []);
                $order = $this->orders->create([
                    'branch_id' => $branch->id, 'type' => $data['order_type'], 'customer_id' => $customer->id,
                    // Partner orders are not created from a POS terminal. Passing
                    // zero violates the register foreign key in strict databases;
                    // null correctly records that no register owns this order.
                    'products' => $products, 'payment_methods' => [], 'payments' => [], 'pos_register_id' => null,
                    'guest_count' => 1, 'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null, 'status' => OrderStatus::Confirmed->value,
                    'additional_payments' => $additionalPayments,
                    'fulfilment' => ['source' => 'partner_api', 'additional_payments' => $additionalPayments,
                        'delivery_address' => $deliveryQuote !== null ? $data['delivery_address'] : null,
                        'customer_delivery_fee' => $deliveryFee,
                        'delivery_distance_km' => $deliveryQuote['distance_km'] ?? null,
                        'delivery_pricing_rule' => $deliveryQuote['pricing_rule'] ?? null],
                ]);
                if ($deliveryQuote !== null) {
                    OrderDelivery::query()->create([
                        'tenant_id' => $context->tenantId(), 'branch_id' => $branch->id, 'order_id' => $order->id,
                        'mode' => 'partner_api', 'provider' => 'partner_api',
                        'external_partner_id' => $context->partner->uuid,
                        'status' => \Modules\Order\Enums\DeliveryStatus::RiderSearching,
                        'assignment_status' => 'awaiting_partner_driver',
                        'pickup_latitude' => $branch->latitude, 'pickup_longitude' => $branch->longitude,
                        'dropoff_latitude' => data_get($data, 'delivery_address.latitude'),
                        'dropoff_longitude' => data_get($data, 'delivery_address.longitude'),
                        'distance_km' => $deliveryQuote['distance_km'], 'customer_delivery_fee' => $deliveryFee,
                    ]);
                }
                $mapping->update(['order_id' => $order->id, 'status' => 'accepted']);

                return $mapping->fresh('order.products.product');
            });
        } finally {
            $previousUser ? Auth::setUser($previousUser) : Auth::forgetUser();
        }

        return PartnerApiResponse::success($this->orderData($mapping), 201);
    }

    public function cancel(Request $request, string $orderId): JsonResponse
    {
        if (! $request->hasHeader('Idempotency-Key') || ! preg_match('/^[A-Za-z0-9._:-]{8,160}$/', (string) $request->header('Idempotency-Key'))) {
            return PartnerApiResponse::error('IDEMPOTENCY_KEY_REQUIRED', 'A stable Idempotency-Key (8-160 safe characters) is required.', 400);
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $mapping = $this->mapping($this->context($request), $orderId);
        $this->auditBranch($request, $mapping);
        if (! $mapping?->order) {
            return PartnerApiResponse::error('ORDER_NOT_FOUND', 'Order was not found.', 404);
        }
        $mapping->order->loadMissing('delivery');
        if ($mapping->order->delivery) {
            return PartnerApiResponse::error(
                'DELIVERY_CANCELLATION_REQUIRES_PROVIDER_WORKFLOW',
                'Delivery orders cannot be cancelled through the generic order endpoint. Use the documented delivery cancellation workflow when it is enabled.',
                409,
            );
        }
        if ($mapping->order->status === OrderStatus::Cancelled) {
            return PartnerApiResponse::success($this->orderData($mapping->load('order.products.product')), 200, ['idempotent_replay' => true]);
        }
        if (in_array($mapping->order->status->value, [OrderStatus::Completed->value, OrderStatus::Refunded->value], true)) {
            return PartnerApiResponse::error('ORDER_NOT_CANCELLABLE', 'This order can no longer be cancelled.', 409);
        }
        $mapping->order->update(['status' => OrderStatus::Cancelled, 'notes' => trim(($mapping->order->notes ? $mapping->order->notes."\n" : '').'Cancellation: '.$data['reason'])]);
        $mapping->order->storeStatusLog(OrderStatus::Cancelled, note: 'PARTNER_API_CANCELLED');
        event(new OrderUpdateStatus($mapping->order, OrderStatus::Cancelled, note: 'PARTNER_API_CANCELLED'));
        $mapping->update(['status' => 'cancelled']);

        return PartnerApiResponse::success($this->orderData($mapping->fresh('order.products.product')));
    }

    public function updateStatus(Request $request, string $orderId): JsonResponse
    {
        if (! $this->hasValidIdempotencyKey($request)) {
            return PartnerApiResponse::error('IDEMPOTENCY_KEY_REQUIRED', 'A stable Idempotency-Key (8-160 safe characters) is required.', 400);
        }
        if (array_diff(array_keys($request->all()), ['status']) !== []) {
            return PartnerApiResponse::error('UNSUPPORTED_FIELDS', 'Only the next status may be supplied.', 422);
        }
        $data = $request->validate([
            'status' => ['required', Rule::in([
                OrderStatus::Confirmed->value,
                OrderStatus::Preparing->value,
                OrderStatus::Ready->value,
                OrderStatus::Served->value,
                OrderStatus::Completed->value,
            ])],
        ]);
        $mapping = $this->mapping($this->context($request), $orderId);
        $this->auditBranch($request, $mapping);
        if (! $mapping?->order) {
            return PartnerApiResponse::error('ORDER_NOT_FOUND', 'Order was not found.', 404);
        }
        $target = OrderStatus::from($data['status']);
        $mapping->order->loadMissing('delivery');
        if ($mapping->order->delivery && $target === OrderStatus::Preparing
            && $mapping->order->payment_status !== OrderPaymentStatus::Paid) {
            return PartnerApiResponse::error('PAYMENT_REQUIRED_BEFORE_KITCHEN',
                'Verify full payment before releasing a partner delivery order to the kitchen.', 409);
        }
        if ($mapping->order->delivery && $target === OrderStatus::Completed) {
            return PartnerApiResponse::error(
                'DELIVERY_STATUS_ENDPOINT_REQUIRED',
                'A delivery order can be completed only by the scoped delivery milestone endpoint after payment verification.',
                409,
            );
        }
        if ($mapping->order->status === $target) {
            return PartnerApiResponse::success($this->orderData($mapping->load('order.products.product')), 200, ['idempotent_replay' => true]);
        }

        $actor = $this->tenantActor($this->context($request));
        if (! $actor) {
            return PartnerApiResponse::error('TENANT_USER_MISSING', 'No active tenant operator is available.', 409);
        }
        $previousUser = Auth::user();
        try {
            Auth::setUser($actor);
            DB::transaction(function () use ($mapping, $target, $actor) {
                $order = Order::query()->withoutGlobalScopes()->whereKey($mapping->order_id)->lockForUpdate()->firstOrFail();
                if ($target === OrderStatus::Preparing && $order->delivery()->exists()) {
                    abort_unless($order->payment_status === OrderPaymentStatus::Paid, 409,
                        'Verify full payment before releasing a partner delivery order to the kitchen.');
                }
                abort_unless($order->allowUpdateStatus() && $order->next_status === $target, 409, 'Only the next valid order status may be applied.');
                abort_if($target === OrderStatus::Completed && $order->payment_status !== OrderPaymentStatus::Paid, 409, 'Collect the outstanding payment before completing this order.');
                $order->update([
                    'status' => $target,
                    'closed_at' => $target === OrderStatus::Completed ? now() : $order->closed_at,
                ]);
                $order->storeStatusLog($target, changedById: $actor->id, note: 'PARTNER_API_STATUS');
                event(new OrderUpdateStatus($order, $target, changedById: $actor->id, note: 'PARTNER_API_STATUS'));
                $mapping->update(['status' => $target->value]);
            });
        } finally {
            $previousUser ? Auth::setUser($previousUser) : Auth::forgetUser();
        }

        return PartnerApiResponse::success($this->orderData($mapping->fresh('order.products.product')));
    }

    public function collectPayment(Request $request, string $orderId, PaymentServiceInterface $payments): JsonResponse
    {
        if (! $this->hasValidIdempotencyKey($request)) {
            return PartnerApiResponse::error('IDEMPOTENCY_KEY_REQUIRED', 'A stable Idempotency-Key (8-160 safe characters) is required.', 400);
        }
        $unknown = array_values(array_diff(array_keys($request->all()), ['method', 'transaction_reference']));
        if ($unknown !== []) {
            return PartnerApiResponse::error(
                'UNSUPPORTED_FIELDS',
                'Do not send amount, currency, tax, total or payment status. NexDine collects the server-calculated outstanding balance.',
                422,
                ['fields' => $unknown],
            );
        }
        $data = $request->validate([
            'method' => ['required', Rule::in([
                PaymentMethod::Cash->value,
                PaymentMethod::UPI->value,
                PaymentMethod::Card->value,
                PaymentMethod::BankTransfer->value,
            ])],
            'transaction_reference' => ['nullable', 'string', 'max:190', 'required_unless:method,cash'],
        ]);
        $mapping = $this->mapping($this->context($request), $orderId);
        $this->auditBranch($request, $mapping);
        if (! $mapping?->order) {
            return PartnerApiResponse::error('ORDER_NOT_FOUND', 'Order was not found.', 404);
        }
        $actor = $this->tenantActor($this->context($request));
        if (! $actor) {
            return PartnerApiResponse::error('TENANT_USER_MISSING', 'No active tenant operator is available.', 409);
        }
        $previousUser = Auth::user();
        try {
            Auth::setUser($actor);
            $replay = DB::transaction(function () use ($mapping, $data, $request, $payments) {
                $order = Order::query()->withoutGlobalScopes()->whereKey($mapping->order_id)->lockForUpdate()->firstOrFail();
                $order->refreshDueAmount();
                if ($order->payment_status === OrderPaymentStatus::Paid || $order->due_amount->amount() <= 0) {
                    return true;
                }
                abort_unless($order->allowAddPayment(), 409, 'Payment cannot be collected for this order state.');
                $payments->processPayment($order, [
                    'method' => $data['method'],
                    'amount' => $order->due_amount->amount(),
                    'transaction_id' => $data['transaction_reference'] ?? 'partner-'.hash('sha256', (string) $request->header('Idempotency-Key')),
                    'meta' => ['source' => 'partner_api', 'partner_order_uuid' => $mapping->uuid],
                ]);

                return false;
            });
        } finally {
            $previousUser ? Auth::setUser($previousUser) : Auth::forgetUser();
        }

        return PartnerApiResponse::success(
            $this->orderData($mapping->fresh('order.products.product')),
            200,
            $replay ? ['idempotent_replay' => true] : [],
        );
    }

    private function hasValidIdempotencyKey(Request $request): bool
    {
        return $request->hasHeader('Idempotency-Key')
            && preg_match('/^[A-Za-z0-9._:-]{8,160}$/', (string) $request->header('Idempotency-Key')) === 1;
    }

    private function auditBranch(Request $request, ?PartnerApiOrderMapping $mapping): void
    {
        if ($mapping) {
            $request->attributes->set('partner_branch_id', (int) $mapping->branch_id);
        }
    }

    private function tenantActor(PartnerContext $context, ?int $branchId = null): ?User
    {
        return User::query()->withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId())
            ->where('is_active', true)
            ->when($branchId, fn ($query) => $query
                ->where(fn ($users) => $users
                    ->where('branch_id', $branchId)
                    ->orWhereNull('branch_id'))
                ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId]))
            ->orderBy('id')
            ->first();
    }

    private function partnerCustomer(PartnerContext $context, Branch $branch, array $data): User
    {
        $phone = preg_replace('/\D+/', '', (string) $data['phone']);
        abort_unless(strlen($phone) >= 8 && strlen($phone) <= 15, 422, 'Customer phone must contain 8 to 15 digits including country code.');
        $customer = User::query()->withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId())
            ->where('phone', $phone)
            ->whereHas('roles', fn ($query) => $query->where('name', DefaultRole::Customer->value))
            ->lockForUpdate()
            ->first();
        if ($customer) {
            return $customer;
        }
        $email = filled($data['email'] ?? null) ? strtolower(trim((string) $data['email'])) : null;
        if ($email && User::query()->withoutGlobalScopes()->where('tenant_id', $context->tenantId())->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $email = null;
        }
        $customer = User::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $branch->id,
            'name' => trim((string) $data['name']),
            'email' => $email,
            'phone' => $phone,
            'phone_country_iso_code' => setting('default_country_iso_code') ?: 'IN',
            'username' => 'partner_customer_'.$context->tenantId().'_'.Str::lower(Str::random(12)),
            'password' => Hash::make(Str::random(64)),
            'is_active' => true,
            'can_login' => true,
        ]);
        $customer->assignRole(DefaultRole::Customer->value);

        return $customer;
    }

    private function mapping(PartnerContext $context, string $orderId): ?PartnerApiOrderMapping
    {
        return PartnerApiOrderMapping::query()
            ->where('partner_id', $context->partner->id)
            ->where('tenant_id', $context->tenantId())
            ->where('uuid', $orderId)
            ->whereHas('order', fn ($query) => $this->scopeOrderToMappingTenant($query, $context))
            ->with('order')
            ->first();
    }

    private function mappingByExternalId(PartnerContext $context, string $externalOrderId): ?PartnerApiOrderMapping
    {
        return PartnerApiOrderMapping::query()
            ->where('partner_id', $context->partner->id)
            ->where('tenant_id', $context->tenantId())
            ->where('external_order_id', $externalOrderId)
            ->whereHas('order', fn ($query) => $this->scopeOrderToMappingTenant($query, $context))
            ->with('order')
            ->first();
    }

    private function scopeOrderToMappingTenant($query, PartnerContext $context): void
    {
        $query->whereColumn('orders.branch_id', 'partner_api_order_mappings.branch_id')
            ->whereHas('branch', fn ($branch) => $branch->withoutGlobalScopes()->where('tenant_id', $context->tenantId()));
    }

    private function orderData(PartnerApiOrderMapping $mapping): array
    {
        $order = $mapping->order;
        $order?->loadMissing('delivery');
        $links = $order ? $this->orderLinks($mapping, $order) : [];

        return [
            'id' => $mapping->uuid,
            'external_order_id' => $mapping->external_order_id,
            'order_reference' => $order?->reference_no,
            'status' => $order?->status?->value ?? $mapping->status,
            'payment_status' => $order?->payment_status?->value,
            'order_type' => $order?->type?->value,
            'currency' => $order?->currency,
            'subtotal' => $order?->subtotal?->amount(),
            'additional_payment' => (object) data_get($order?->fulfilmentDetails() ?? [], 'additional_payments', []),
            'total' => $order?->total?->amount(),
            'due_amount' => $order?->due_amount?->amount(),
            'delivery' => $order ? DeliveryReadModel::customer($order->delivery) : null,
            'items' => $order?->products?->map(fn ($item) => [
                'name' => $item->product?->name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price?->amount(),
                'total' => $item->total?->amount(),
            ])->values() ?? [],
            'links' => (object) $links,
            'created_at' => $order?->created_at?->toIso8601String(),
            'updated_at' => $order?->updated_at?->toIso8601String(),
        ];
    }

    private function orderLinks(PartnerApiOrderMapping $mapping, Order $order): array
    {
        $order->loadMissing('branch.tenant');
        $domain = trim((string) $order->branch?->tenant?->domain);
        $frontend = rtrim($domain !== ''
            ? (preg_match('~^https?://~i', $domain) ? $domain : 'https://'.$domain)
            : (string) (config('app.frontend_url') ?: config('app.url')), '/');
        $menuSlug = (string) OnlineMenu::query()
            ->withoutGlobalActive()
            ->withOutGlobalBranchPermission()
            ->where('branch_id', $order->branch_id)
            ->where('is_active', true)
            ->latest('updated_at')
            ->value('slug');
        $invoice = $order->getInvoice();
        $trackingToken = app(CustomerTrackingToken::class)->issue(
            (int) $order->branch?->tenant_id,
            (string) $order->reference_no,
        );

        return [
            'self' => url('/v1/partner/orders/'.$mapping->uuid),
            'tracking' => $menuSlug !== ''
                ? $frontend.'/online-menu/'.rawurlencode($menuSlug).'/orders/'.rawurlencode((string) $order->reference_no)
                    .'?tracking_token='.rawurlencode($trackingToken)
                : null,
            'invoice' => $invoice?->getPDFUrl(),
            'feedback' => $frontend.'/feedback/orders/'.rawurlencode((string) $order->reference_no)
                .'?source=partner&token='.rawurlencode(OrderFeedbackAccess::token($order)),
            'reorder' => $menuSlug !== '' ? $frontend.'/online-menu/'.rawurlencode($menuSlug) : null,
        ];
    }

    private function unknownOrderFields(array $payload): array
    {
        $unknown = array_diff(array_keys($payload), ['external_order_id', 'branch_id', 'order_type', 'customer', 'items', 'notes', 'additional_payment', 'delivery_address']);
        foreach (array_diff(array_keys((array) ($payload['customer'] ?? [])), ['name', 'phone', 'email']) as $field) {
            $unknown[] = 'customer.'.$field;
        }
        foreach ((array) ($payload['items'] ?? []) as $index => $item) {
            foreach (array_diff(array_keys((array) $item), ['product_id', 'quantity']) as $field) {
                $unknown[] = "items.{$index}.{$field}";
            }
        }
        foreach (array_diff(array_keys((array) ($payload['delivery_address'] ?? [])),
            ['address_line1', 'city', 'landmark', 'latitude', 'longitude']) as $field) {
            $unknown[] = 'delivery_address.'.$field;
        }

        return array_values(array_unique($unknown));
    }

    private function context(Request $request): PartnerContext
    {
        return $request->attributes->get('partner_context');
    }
}
