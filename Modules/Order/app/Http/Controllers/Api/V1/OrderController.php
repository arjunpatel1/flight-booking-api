<?php

namespace Modules\Order\Http\Controllers\Api\V1;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Cart\Facades\Cart;
use Modules\Cart\Support\PublicTenantGuard;
use Modules\Core\Http\Controllers\Controller;
use Modules\Currency\Models\CurrencyRate;
use Modules\Order\Delivery\CustomerDeliveryQuote;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderUpdated;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Http\Requests\Api\V1\CancelOrRefundOrderRequest;
use Modules\Order\Http\Requests\Api\V1\OrderPaymentRequest;
use Modules\Order\Http\Requests\Api\V1\PublicQrOrderRequest;
use Modules\Order\Http\Requests\Api\V1\SaveOrderRequest;
use Modules\Order\Http\Requests\Api\V1\SplitOrderRequest;
use Modules\Order\Jobs\RefundCustomerCancelledOrder;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDelivery;
use Modules\Order\Services\Course\FireCourseService;
use Modules\Order\Services\Order\OrderServiceInterface;
use Modules\Order\Services\OrderPayment\OrderPaymentServiceInterface;
use Modules\Order\Services\SaveOrder\SaveOrderServiceInterface;
use Modules\Order\Services\SplitOrder\SplitOrderServiceInterface;
use Modules\Order\Support\CustomerTrackingToken;
use Modules\Order\Support\OrderSourcePresenter;
use Modules\Order\Transformers\Api\V1\ActiveOrderResource;
use Modules\Order\Transformers\Api\V1\OrderResource;
use Modules\Order\Transformers\Api\V1\PublicOrderTrackingResource;
use Modules\Order\Transformers\Api\V1\ShowOrderResource;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Models\Payment;
use Modules\Pos\Services\QRCode\QRCodeServiceInterface;
use Modules\Printer\Enum\PrintContentType;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Table;
use Modules\Setting\Services\Delivery\DeliveryGeocoder;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;
use Modules\User\Http\Concerns\ResolvesAppCustomer;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OrderController extends Controller
{
    public function customerDeliveryLocationSearch(Request $request, DeliveryGeocoder $geocoder): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'required_without_all:latitude,longitude', 'string', 'min:3', 'max:200'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'menu_reference' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'integer'],
        ]);
        abort_unless(filled($data['menu_reference'] ?? null) || filled($data['branch_id'] ?? null), 422, 'Select a restaurant branch.');
        filled($data['menu_reference'] ?? null)
            ? PublicTenantGuard::menu($request, $data['menu_reference'])
            : PublicTenantGuard::branch($request, $data['branch_id']);

        $results = isset($data['latitude'], $data['longitude'])
            ? $geocoder->reverse((float) $data['latitude'], (float) $data['longitude'])
            : $geocoder->search($data['q']);

        return ApiResponse::success(['results' => $results]);
    }

    public function customerCancelLink(string $token): RedirectResponse
    {
        $link = $this->customerCancelLinkData($token);
        $reference = $link['reference'];
        $order = Order::query()->withoutGlobalScopes()
            ->with(['branch.tenant', 'branch.onlineMenus'])
            ->where('reference_no', $reference)
            ->firstOrFail();
        $slug = (string) $order->branch?->onlineMenus
            ?->where('is_active', true)
            ->sortByDesc('updated_at')
            ->first()?->slug;
        abort_if($slug === '', 404, 'An active online menu is required to cancel this order.');
        $domain = trim((string) $order->branch?->tenant?->domain);
        $frontendUrl = $domain !== ''
            ? (preg_match('~^https?://~i', $domain) ? $domain : 'https://'.$domain)
            : (string) (config('app.frontend_url') ?: config('app.url'));

        return redirect()->away(rtrim($frontendUrl, '/').'/online-menu/'.rawurlencode($slug).'/orders/'.rawurlencode($reference).'/cancel?cancel_token='.rawurlencode($token));
    }

    public function staffOrderLink(string $reference): RedirectResponse
    {
        $isReference = preg_match('/^ORD-[A-Z0-9]{8,64}$/', $reference) === 1;
        $isNumericId = ctype_digit($reference) && (int) $reference > 0;
        abort_unless($isReference || $isNumericId, 404);
        $order = Order::query()->withoutGlobalScopes()
            ->with('branch.tenant')
            ->when($isReference,
                fn ($query) => $query->where('reference_no', $reference),
                fn ($query) => $query->whereKey((int) $reference))
            ->firstOrFail();
        $domain = strtolower(trim((string) $order->branch?->tenant?->domain));
        abort_unless(filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME), 404);

        // The redirect discloses no order data. The destination still enforces
        // the tenant admin session and order permission before showing details.
        return redirect()->away('https://'.$domain.'/admin/orders/'.$order->id.'/show');
    }

    public function customerPaymentLink(string $token): RedirectResponse
    {
        $link = Cache::get('whatsapp-order-payment-link:'.hash('sha256', $token));
        abort_unless(is_array($link) && filled($link['url'] ?? null) && filled($link['expires_at'] ?? null)
            && now()->lt(\Illuminate\Support\Carbon::parse($link['expires_at'])),
            410, 'This payment link expired. Request a new payment link from the restaurant.');
        $url = (string) $link['url'];
        abort_unless(filter_var($url, FILTER_VALIDATE_URL) && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https',
            422, 'The payment link is invalid.');

        return redirect()->away($url);
    }

    public function customerCancelStatus(string $token): JsonResponse
    {
        $link = $this->customerCancelLinkData($token);
        $order = Order::query()->withoutGlobalScopes()->with('products')->where('reference_no', $link['reference'])->firstOrFail();
        $available = ($order->payment_status->isUnpaid() || $order->payment_status->isPaid())
            && in_array($order->status, [OrderStatus::Pending, OrderStatus::Confirmed], true)
            && $order->products->every(fn ($product) => in_array($product->status, [OrderProductStatus::Pending, OrderProductStatus::Cancelled], true));

        return response()->json([
            'expires_at' => $link['expires_at'],
            'can_cancel' => $available,
            'refund_required' => $available && $order->payment_status->isPaid(),
            'message' => $available
                ? ($order->payment_status->isPaid() ? 'Cancellation is available. Your verified payment will be refunded after confirmation.' : 'Cancel before the kitchen starts preparing this order.')
                : 'This order can no longer be cancelled because kitchen preparation has started or the order is closed.',
        ]);
    }

    private function customerCancelLinkData(string $token): array
    {
        $link = Cache::get('customer-order-cancel-link:'.hash('sha256', $token));
        abort_unless(is_array($link) && filled($link['reference'] ?? null) && filled($link['expires_at'] ?? null)
            && now()->lt(\Illuminate\Support\Carbon::parse($link['expires_at'] ?? null)),
            410, 'This cancellation link expired. Request a new link from the restaurant.');

        return $link;
    }

    use ResolvesAppCustomer;

    /**
     * Create a new instance of OrderCreateController
     */
    public function __construct(
        protected OrderServiceInterface $service,
        protected SaveOrderServiceInterface $saveOrderService,
        protected OrderPaymentServiceInterface $paymentService,
        protected SplitOrderServiceInterface $splitOrderService,
        protected QRCodeServiceInterface $qrCodes,
    ) {}

    /**
     * This method retrieves and returns a list of Payment models.
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: OrderResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * Archive a completed order while retaining its financial and audit links.
     */
    public function destroy(int|string $id): JsonResponse
    {
        $order = Order::query()
            ->where(static fn ($query) => $query->whereKey($id)->orWhere('reference_no', $id))
            ->firstOrFail();

        abort_unless(
            $order->status === OrderStatus::Completed,
            422,
            __('Only completed orders can be deleted.')
        );

        $order->delete();

        return ApiResponse::success();
    }

    /**
     * Aggregate KPIs for the orders list (honours the same filters).
     */
    public function stats(Request $request): JsonResponse
    {
        return ApiResponse::success(
            body: $this->service->stats($request->get('filters', []))
        );
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $request->get('filters', []);
        $sorts = $request->get('sorts', []);

        $query = Order::query()
            ->with([
                'branch:id,uuid,name',
                'customer:id,name',
                'table:id,name',
                'aggregatorOrderMapping.integration:id,provider,name',
                'partnerApiOrderMapping:id,order_id',
                'whatsAppOrderSession:id,order_id',
            ])
            ->filters(is_array($filters) ? $filters : [])
            ->sortBy(is_array($sorts) ? $sorts : []);

        $groupBy = data_get($filters, 'group_by');

        if ($groupBy === 'table') {
            $query->orderBy('table_id')->orderBy('created_at');
        } elseif ($groupBy === 'type') {
            $query->orderBy('type')->orderBy('created_at');
        }

        return response()->streamDownload(function () use ($query) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'reference_no',
                'order_number',
                'branch',
                'customer',
                'table',
                'guest_count',
                'type',
                'source',
                'status',
                'payment_status',
                'currency',
                'subtotal',
                'total',
                'due_amount',
                'created_at',
            ]);

            $query->chunk(500, function ($orders) use ($stream) {
                foreach ($orders as $order) {
                    $source = OrderSourcePresenter::make($order);
                    fputcsv($stream, [
                        $this->sanitizeCsvValue($order->reference_no),
                        $this->sanitizeCsvValue($order->order_number),
                        $this->sanitizeCsvValue($order->branch?->name),
                        $this->sanitizeCsvValue($order->customer?->name),
                        $this->sanitizeCsvValue($order->table?->name),
                        $order->guest_count,
                        $order->type->value,
                        $source['source_label'],
                        $order->status->value,
                        $order->payment_status->value,
                        $order->currency,
                        $order->getRawOriginal('subtotal'),
                        $order->getRawOriginal('total'),
                        $order->getRawOriginal('due_amount'),
                        $order->created_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($stream);
        }, 'orders-'.now()->format('YmdHis').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Sanitize CSV value to prevent formula injection.
     * Prefixes values starting with =, +, -, @ with a tab character.
     */
    private function sanitizeCsvValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $firstChar = substr($value, 0, 1);
        if (in_array($firstChar, ['=', '+', '-', '@'], true)) {
            return "\t".$value;
        }

        return $value;
    }

    /**
     * This method cancel order
     *
     * @throws Throwable
     */
    public function cancel(CancelOrRefundOrderRequest $request, int|string $id): JsonResponse
    {
        $this->service->cancel($id, $request->validated());

        return ApiResponse::success(
            body: new OrderResource($this->service->show($id)),
            message: __('admin::messages.resources_cancelled', ['resource' => $this->service->label()])
        );
    }

    /**
     * Split a bill: move selected line items into a new, separately-payable child
     * order. Totals/taxes are recomputed for both bills.
     */
    public function split(SplitOrderRequest $request, int|string $orderId): JsonResponse
    {
        $order = Order::query()->findOrFail($orderId);

        $result = $this->splitOrderService->split($order, $request->validated('items'));

        return ApiResponse::created(
            body: [
                'parent' => new OrderResource($result['parent']),
                'child' => new OrderResource($result['child']),
            ],
            resource: $this->service->label()
        );
    }

    /**
     * This method Refund order
     *
     * @throws Throwable
     */
    public function refund(CancelOrRefundOrderRequest $request, int|string $id): JsonResponse
    {
        $this->service->refund($id, $request->validated());

        return ApiResponse::success(body: ['success' => true], message: __('order::messages.order_refunded'));
    }

    /**
     * This method edit order
     *
     * @throws Throwable
     */
    public function edit(string $cartId, int|string $id): JsonResponse
    {
        return ApiResponse::success(body: [
            ...$this->service->initEdit($id),
            'cart' => Cart::instance(),
        ]);
    }

    /**
     * This method retrieves and returns a single Payment model based on the provided identifier.
     */
    public function show(int|string $id): JsonResponse
    {
        return ApiResponse::success(
            body: new ShowOrderResource($this->service->show($id))
        );
    }

    /**
     * Public limited order tracking for QR/online customers.
     */
    public function publicTracking(Request $request, string $reference): JsonResponse
    {
        $customer = $this->optionalCustomerForRequest($request);
        abort_if(
            $customer === null && ! (bool) setting('customer_app_guest_checkout_enabled', true),
            403,
            'Guest checkout is disabled for this restaurant. Sign in to continue.'
        );
        $tenantId = PublicTenantGuard::tenantId($request);
        $order = Order::query()
            ->with([
                'customer:id,name',
                'table:id,name',
                'branch:id,uuid,name',
                'branch.onlineMenus:id,branch_id,slug,is_active',
                'products.product:id,uuid,name,menu_id,image_thumbnail_path,image_medium_path,image_original_path',
                'discount.discountable',
                'delivery',
                'invoices:id,order_id,invoice_number,invoice_kind,total,currency,currency_rate,issued_at,uuid',
            ])
            // Signed-in customers can only read their own orders. Anonymous
            // tracking is limited to anonymous orders and still requires the
            // tenant-scoped, high-entropy public reference.
            ->when(
                $customer,
                fn ($query) => $query->where('customer_id', $customer->id),
                fn ($query) => $query->whereNull('customer_id')
            )
            ->whereHas('branch', fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('reference_no', $reference)
            ->firstOrFail();

        return ApiResponse::success(new PublicOrderTrackingResource($order));
    }

    /** Limited tracking opened from a time-limited customer notification link. */
    public function tokenTracking(Request $request, string $reference, CustomerTrackingToken $tokens): JsonResponse
    {
        $data = $request->validate(['tracking_token' => ['required', 'string', 'max:4096']]);
        $tenantId = $tokens->validate($data['tracking_token'], $reference);
        abort_unless($tenantId, 403, 'This tracking link is invalid or expired.');

        $order = Order::query()->withoutGlobalScopes()
            ->with([
                'customer:id,name', 'table:id,name', 'branch:id,uuid,name',
                'branch.onlineMenus:id,branch_id,slug,is_active',
                'products.product:id,uuid,name,menu_id,image_thumbnail_path,image_medium_path,image_original_path',
                'discount.discountable', 'delivery',
                'invoices:id,order_id,invoice_number,invoice_kind,total,currency,currency_rate,issued_at,uuid',
            ])
            ->whereHas('branch', fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('reference_no', $reference)
            ->firstOrFail();

        return ApiResponse::success(new PublicOrderTrackingResource($order));
    }

    /**
     * Order history for the signed-in customer of a branded customer app.
     *
     * Scoped to the customer resolved from the sanctum token and the tenant
     * established by ResolveCustomerAppContext, so history never leaks across
     * restaurants even when the same person orders from several tenants.
     */
    public function customerAppOrders(Request $request): JsonResponse
    {
        $customer = $this->customerForRequest($request);
        $tenantId = PublicTenantGuard::tenantId($request);

        $orders = Order::query()
            ->with([
                'customer:id,name',
                'table:id,name',
                'branch:id,uuid,name',
                'branch.onlineMenus:id,branch_id,slug,is_active',
                'products.product:id,uuid,name,menu_id,image_thumbnail_path,image_medium_path,image_original_path',
                'discount.discountable',
                'delivery',
                'invoices:id,order_id,invoice_number,invoice_kind,total,currency,currency_rate,issued_at,uuid',
            ])
            ->where('customer_id', $customer->id)
            ->whereHas('branch', fn ($query) => $query->where('tenant_id', $tenantId))
            ->latest('order_date')
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 20) ?: 20, 50));

        return ApiResponse::pagination(
            paginator: $orders,
            resource: PublicOrderTrackingResource::class,
        );
    }

    /**
     * Load an editable, customer-owned order into an isolated cart.
     */
    public function customerAppEdit(Request $request, string $reference, string $cartId): JsonResponse
    {
        $order = $this->editableCustomerOrder($request, $reference);
        if ($request->boolean('validate_only')) {
            return ApiResponse::success(body: ['order' => new PublicOrderTrackingResource($order)]);
        }
        $edit = $this->service->initEdit($order->id);

        return ApiResponse::success(body: [
            ...$edit,
            'cart' => Cart::instance(),
            'fulfilment' => $order->fulfilmentDetails(),
            'order' => new PublicOrderTrackingResource($order->fresh()),
        ]);
    }

    /**
     * Replace the cart lines of an editable customer order and recalculate it.
     * Fulfilment, customer, branch and POS ownership are retained server-side.
     */
    public function customerAppUpdate(Request $request, string $reference, string $cartId): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:999'],
            'version' => ['required', 'string', 'max:64'],
        ]);

        $order = DB::transaction(function () use ($request, $reference, $cartId, $data) {
            $order = $this->editableCustomerOrder($request, $reference, true);
            abort_unless(
                hash_equals($order->updated_at?->toISOString() ?? '', (string) $data['version']),
                409,
                'This order changed while you were editing it. Reload the latest order before trying again.'
            );

            PublicTenantGuard::assertCartProducts($request, $order->branch_id, Cart::items());

            return $this->saveOrderService->update($order->id, [
                'cart_id' => $cartId,
                'type' => $order->type->value,
                'table_id' => $order->table_id,
                'register_id' => $order->pos_register_id,
                'session_id' => $order->pos_session_id,
                'guest_count' => $data['guest_count'] ?? $order->guest_count,
                'notes' => $data['notes'] ?? $order->notes,
                // Move a legacy public order's labelled address suffix into
                // structured fulfilment in the same atomic order update.
                'fulfilment' => $order->fulfilmentDetails(),
                'auto_print_kot' => true,
            ]);
        });

        return ApiResponse::updated(
            body: new PublicOrderTrackingResource($order->fresh([
                'customer:id,name', 'table:id,name', 'branch:id,uuid,name',
                'branch.onlineMenus:id,branch_id,slug,is_active',
                'products.product:id,uuid,name,menu_id', 'discount.discountable',
                'invoices:id,order_id,invoice_number,invoice_kind,total,currency,currency_rate,issued_at,uuid',
            ])),
            resource: $this->service->label(),
        );
    }

    private function editableCustomerOrder(Request $request, string $reference, bool $lock = false): Order
    {
        $customer = $this->customerForRequest($request);
        $tenantId = PublicTenantGuard::tenantId($request);
        $query = Order::query()
            ->with(['branch', 'customer', 'products.product', 'products.options.option.type', 'products.options.values', 'taxes', 'table', 'discount.gift'])
            ->where('reference_no', $reference)
            ->where('customer_id', $customer->id)
            ->whereHas('branch', fn ($branch) => $branch->where('tenant_id', $tenantId));
        if ($lock) {
            $query->lockForUpdate();
        }
        $branchId = $request->attributes->get('branch_id') ?: $request->input('branch_id');
        if ($request->filled('menu_reference')) {
            $menu = PublicTenantGuard::menu($request, (string) $request->input('menu_reference'));
            abort_if($branchId && (int) $branchId !== (int) $menu->branch_id, 404);
            $branchId = $menu->branch_id;
        }
        abort_unless($branchId, 422, 'Choose the restaurant before editing an order.');
        $query->where('branch_id', PublicTenantGuard::branch($request, $branchId)->id);
        $order = $query->firstOrFail();

        abort_unless(
            $order->payment_status->isUnpaid()
            && in_array($order->status, [OrderStatus::Pending, OrderStatus::Confirmed], true)
            && $order->products->every(fn ($product) => in_array($product->status, [OrderProductStatus::Pending, OrderProductStatus::Cancelled], true)),
            422,
            'This order can no longer be edited because payment or preparation has already started.'
        );

        return $order;
    }

    /** Cancel a customer order before preparation and queue any required gateway refund. */
    public function customerAppCancel(Request $request, string $reference): JsonResponse
    {
        $customer = $this->customerForRequest($request);
        $tenantId = PublicTenantGuard::tenantId($request);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'cancel_token' => ['nullable', 'string', 'max:100'],
        ]);

        $order = DB::transaction(function () use ($customer, $tenantId, $reference, $data) {
            $order = Order::query()
                ->where('reference_no', $reference)
                ->where('customer_id', $customer->id)
                ->whereHas('branch', fn ($query) => $query->where('tenant_id', $tenantId))
                ->lockForUpdate()
                ->firstOrFail();

            if (data_get($order->fulfilmentDetails(), 'channel') === 'whatsapp_catalog') {
                $token = (string) ($data['cancel_token'] ?? '');
                abort_if($token === '' || $this->customerCancelLinkData($token)['reference'] !== $reference,
                    410, 'This cancellation link expired. Request a new link from the restaurant.');
            }

            $order->loadMissing('products');
            abort_unless(
                ($order->payment_status->isUnpaid() || $order->payment_status->isPaid())
                && in_array($order->status, [OrderStatus::Pending, OrderStatus::Confirmed], true)
                && $order->products->every(fn ($product) => in_array($product->status, [OrderProductStatus::Pending, OrderProductStatus::Cancelled], true)),
                422,
                'This order can no longer be cancelled because kitchen preparation has started or the order is closed.'
            );

            $order->update(['status' => OrderStatus::Cancelled]);
            $order->storeStatusLog(OrderStatus::Cancelled, changedById: $customer->id, note: $data['note'] ?? 'CUSTOMER_CANCELLED');
            event(new OrderUpdateStatus(order: $order, status: OrderStatus::Cancelled, changedById: $customer->id, note: $data['note'] ?? 'CUSTOMER_CANCELLED'));

            return $order;
        });

        if ($order->payment_status->isPaid()) {
            RefundCustomerCancelledOrder::dispatch($order->id, $tenantId);
        }

        return ApiResponse::success(new PublicOrderTrackingResource($order));
    }

    /**
     * This method stores the provided data into storage for the Order model.
     *
     * @throws Throwable
     */
    public function store(SaveOrderRequest $request, string $cartId): JsonResponse
    {
        $order = $this->saveOrderService->create([
            ...$request->validated(),
            'cart_id' => $cartId,
        ]);

        return ApiResponse::created(
            body: [
                'id' => $order->id,
                'order_id' => $order->reference_no,
                'reference_no' => $order->reference_no,
                'order_number' => $order->order_number,
                'cart' => Cart::instance(),
            ],
            resource: $this->service->label()
        );
    }

    /**
     * Store a public QR/online-menu order.
     *
     * @throws Throwable
     */
    public function customerDeliveryQuote(Request $request, CustomerDeliveryQuote $calculator, string $cartId): JsonResponse
    {
        $data = $request->validate([
            'menu_reference' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'integer'],
            'delivery_address' => ['required', 'array'],
            'delivery_address.id' => ['nullable', 'uuid'],
            'delivery_address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        abort_unless(filled($data['menu_reference'] ?? null) || filled($data['branch_id'] ?? null), 422, 'Select a restaurant branch.');
        $branchId = filled($data['menu_reference'] ?? null)
            ? PublicTenantGuard::menu($request, $data['menu_reference'])->branch_id
            : $data['branch_id'];
        $branch = PublicTenantGuard::branch($request, $branchId);
        app(\Modules\Order\Delivery\DeliveryAvailability::class)->assertAvailable($branch);
        abort_unless((bool) setting('delivery_enabled', false), 422,
            'Delivery checkout is disabled for this restaurant. Please choose pickup or contact the restaurant.');
        $customer = $this->optionalCustomerForRequest($request);
        abort_unless($customer && (int) $customer->tenant_id === (int) $branch->tenant_id, 403, 'Sign in to this restaurant to check delivery.');
        $data['delivery_address'] = app(\Modules\Order\Delivery\DeliveryAddressResolver::class)
            ->resolve($data['delivery_address'], $customer, (int) $branch->tenant_id);
        abort_unless((bool) setting('customer_app_delivery_enabled', true)
            && collect($branch->order_types)->contains(fn ($type) => ($type instanceof OrderType ? $type->value : $type) === OrderType::Delivery->value),
            422, 'Delivery is unavailable from this restaurant branch.');
        Cart::addBranch($branch);
        Cart::addOrderType(OrderType::Delivery);
        Cart::addTaxes();
        abort_if(Cart::items()->isEmpty(), 422, 'Your cart is empty. Refresh checkout and try again.');
        PublicTenantGuard::assertCartProducts($request, $branch->id, Cart::items());
        $subtotal = Cart::subTotal()->amount();
        $quote = $calculator->calculate(
            $branch,
            $data['delivery_address'],
            $subtotal,
            $this->customerDeliverySettings(),
        );
        try {
            $quote = app(\Modules\Order\Delivery\ThirdPartyCustomerPricing::class)->apply(
                $branch, $data['delivery_address'], $quote, 'CHECKOUT-'.(string) \Illuminate\Support\Str::uuid(), true,
            );
        } catch (\Modules\Order\Delivery\ProviderUnavailable) {
            abort(503, 'Delivery availability cannot be confirmed right now.');
        }
        if ($quote['serviceable'] && $branch->delivery_minimum_order !== null && $subtotal < (float) $branch->delivery_minimum_order) {
            $quote = [...$quote, 'serviceable' => false, 'failure_code' => 'MINIMUM_ORDER_NOT_MET',
                'message' => sprintf('Add more items to meet this outlet\'s %s %.2f delivery minimum.', $branch->currency, $branch->delivery_minimum_order)];
        }

        return ApiResponse::success([
            ...$quote, 'currency' => $branch->currency, 'cart_total' => Cart::total()->amount(),
            'payable_total' => $quote['serviceable'] ? \Modules\Order\Delivery\DeliveryMoney::add(Cart::total()->amount(), $quote['delivery_fee'], $branch->currency) : null,
        ]);
    }

    private function customerDeliverySettings(): array
    {
        return app(CustomerDeliveryQuote::class)->settings();
    }

    public function publicQrStore(PublicQrOrderRequest $request, string $cartId): JsonResponse
    {
        $data = $request->validated();

        abort_unless(
            (bool) setting('customer_app_enabled', true),
            403,
            'Customer ordering is currently disabled for this restaurant.'
        );

        /** @var Branch $branch */
        $branch = PublicTenantGuard::branch($request, $data['branch_id']);
        Cart::addBranch($branch);
        $paymentMethod = strtolower(trim((string) ($data['payment_method'] ?? 'cash')));
        abort_unless(in_array($paymentMethod, ['cash', 'cash_on_delivery', 'pay_at_counter', 'upi', 'razorpay', 'wallet'], true), 422, 'This payment method is not available for customer checkout.');
        if ($paymentMethod === 'cash_on_delivery') {
            abort_unless(($data['type'] ?? null) === OrderType::Delivery->value && (bool) setting('customer_payment_cod_enabled', false), 422, 'Cash on delivery is not available for this order.');
        }
        if (in_array($paymentMethod, ['cash', 'pay_at_counter'], true)) {
            abort_unless((bool) setting('customer_payment_counter_enabled', true), 422, 'Pay at counter is disabled.');
        }
        if ($paymentMethod === 'razorpay') {
            abort_unless((bool) setting('customer_payment_razorpay_enabled', false), 422, 'Razorpay is disabled.');
        }
        if (($data['type'] ?? null) === OrderType::Delivery->value) {
            abort_if($paymentMethod === 'razorpay' && ! \Modules\Order\Delivery\DeliveryPaymentPolicy::allowsOnlinePayment(), 422,
                'Online payment is not available for delivery from this restaurant. Choose cash on delivery or pickup.');
            abort_if($paymentMethod === 'cash_on_delivery' && ! \Modules\Order\Delivery\DeliveryPaymentPolicy::allowsCashOnDelivery(), 422,
                'Cash on delivery is not available for this order.');
        }
        $enabledPaymentMethods = collect($branch->payment_methods ?? [])
            ->map(fn ($method) => strtolower((string) (is_array($method) ? ($method['value'] ?? $method['id'] ?? '') : $method)))
            ->filter();
        $branchAllowsPayment = match ($paymentMethod) {
            'wallet' => true,
            'cash_on_delivery', 'pay_at_counter', 'cash' => $enabledPaymentMethods->contains('cash'),
            'razorpay' => $enabledPaymentMethods->intersect(['razorpay', 'upi', 'card', 'mobile_wallet'])->isNotEmpty(),
            default => $enabledPaymentMethods->contains($paymentMethod),
        };
        abort_if($enabledPaymentMethods->isNotEmpty() && ! $branchAllowsPayment, 422, 'This payment method is disabled for this branch.');
        $awaitingUpiPayment = in_array($paymentMethod, ['upi', 'razorpay'], true);
        $kotReleasePolicy = (string) setting('online_order_kot_release_policy', 'after_payment_or_approval');
        $requiresStaffApproval = $kotReleasePolicy !== 'immediate'
            && ($awaitingUpiPayment || in_array($paymentMethod, ['cash', 'cash_on_delivery', 'pay_at_counter'], true));

        $hasTableCredential = filled($data['table_token'] ?? null) || filled($data['table_qr_payload'] ?? null);
        $orderType = OrderType::from($data['type'] ?? ($hasTableCredential ? OrderType::DineIn->value : OrderType::Takeaway->value));
        $table = $orderType === OrderType::DineIn
            ? $this->resolveAuthorizedPublicTable($branch, $data)
            : null;
        $customer = $this->optionalCustomerForRequest($request);
        abort_if($customer === null, 401, 'Sign in is required before placing an order.');
        if ($orderType === OrderType::Delivery) {
            $data['delivery_address'] = app(\Modules\Order\Delivery\DeliveryAddressResolver::class)
                ->resolve($data['delivery_address'] ?? [], $customer, (int) $branch->tenant_id);
        }
        abort_if(
            $table !== null && $hasTableCredential && ! (bool) setting('customer_app_table_qr_enabled', true),
            403,
            'Table QR ordering is disabled for this restaurant.'
        );
        abort_if(
            $orderType === OrderType::Takeaway && ! (bool) setting('customer_app_pickup_enabled', true),
            403,
            'Pickup ordering is disabled for this restaurant.'
        );
        if ($orderType === OrderType::Delivery) {
            app(\Modules\Order\Delivery\DeliveryAvailability::class)->assertAvailable($branch);
            abort_unless((bool) setting('delivery_enabled', false), 422,
                'Delivery checkout is disabled for this restaurant. Please choose pickup or contact the restaurant.');
            abort_unless(
                (bool) setting('customer_app_delivery_enabled', true),
                403,
                'Delivery ordering is disabled for this restaurant.'
            );
            abort_unless(
                collect($branch->order_types)
                    ->contains(fn ($type) => ($type instanceof OrderType ? $type->value : $type) === OrderType::Delivery->value),
                422,
                'Delivery is not available from this restaurant branch.'
            );
            abort_unless(
                $customer
                && (int) $customer->tenant_id === (int) $branch->tenant_id
                && $customer->hasRole(\Modules\User\Enums\DefaultRole::Customer->value),
                403,
                'Sign in to this restaurant before placing a delivery order.'
            );
            abort_if(
                $branch->delivery_minimum_order !== null
                && Cart::subTotal()->amount() < (float) $branch->delivery_minimum_order,
                422,
                sprintf(
                    'Delivery orders from this branch require a minimum subtotal of %s %.2f.',
                    $branch->currency,
                    (float) $branch->delivery_minimum_order
                )
            );
        }
        Cart::addOrderType($orderType);
        Cart::addTaxes();

        abort_if(Cart::items()->isEmpty(), 422, __('order::messages.order_must_contain_at_least_one_active_product'));
        PublicTenantGuard::assertCartProducts($request, $branch->id, Cart::items());

        $deliveryQuote = null;
        if ($orderType === OrderType::Delivery) {
            $deliveryQuote = app(CustomerDeliveryQuote::class)->calculate(
                $branch,
                $data['delivery_address'] ?? [],
                Cart::subTotal()->amount(),
                $this->customerDeliverySettings(),
            );
            try {
                $deliveryQuote = app(\Modules\Order\Delivery\ThirdPartyCustomerPricing::class)->apply(
                    $branch, $data['delivery_address'] ?? [], $deliveryQuote,
                    'ORDER-'.(string) \Illuminate\Support\Str::uuid(),
                    in_array(strtolower((string) ($data['payment_method'] ?? '')), ['cod', 'cash', 'cash_on_delivery'], true),
                );
            } catch (\Modules\Order\Delivery\ProviderUnavailable) {
                abort(503, 'Delivery availability cannot be confirmed right now.');
            }
            abort_unless($deliveryQuote['serviceable'], 422, $deliveryQuote['message']);
        }
        if ($orderType === OrderType::Delivery && (bool) setting('mandatory_delivery_location_enabled', false)) {
            abort_unless(\Modules\Order\Delivery\DeliveryLocation::fromAddress($data['delivery_address'] ?? []),
                422, 'Choose a delivery map pin or use a saved address with confirmed coordinates.');
        }
        $deliveryFee = (float) ($deliveryQuote['delivery_fee'] ?? 0);
        $payableTotal = \Modules\Order\Delivery\DeliveryMoney::add(Cart::total()->amount(), $deliveryFee, $branch->currency);
        if ($deliveryQuote !== null && isset($data['expected_payable_total'])) {
            abort_if(\Modules\Order\Delivery\DeliveryMoney::minor($data['expected_payable_total'], $branch->currency)
                !== \Modules\Order\Delivery\DeliveryMoney::minor($payableTotal, $branch->currency),
                409, 'Delivery pricing changed. Refresh checkout and review the new total before placing your order.');
        }

        if ($table) {
            $existingGuests = $table->activeOrders->sum(fn (Order $order) => (int) ($order->guest_count ?? 1));
            $newGuests = (int) ($data['guest_count'] ?? 1);

            abort_if(
                ($existingGuests + $newGuests) > $table->capacity,
                422,
                __('seatingplan::tables.capacity_exceeded')
            );
        }

        $order = DB::transaction(function () use ($request, $branch, $data, $orderType, $table, $customer, $requiresStaffApproval, $paymentMethod, $deliveryQuote, $deliveryFee, $payableTotal) {
            $wallet = null;
            if ($paymentMethod === 'wallet') {
                $wallet = DB::table('customer_wallet_accounts')->where('tenant_id', $customer->tenant_id)
                    ->where('customer_id', $customer->id)->where('currency', $branch->currency)->lockForUpdate()->first();
                abort_unless($wallet && \Modules\Order\Delivery\DeliveryMoney::minor($wallet->balance, $branch->currency) >= \Modules\Order\Delivery\DeliveryMoney::minor($payableTotal, $branch->currency), 422, 'Your wallet balance is not enough for this order.');
            }
            $details = \Modules\Order\Support\OnlineOrderDetails::fromCheckout($data);
            $details['fulfilment'] = array_filter([
                ...($details['fulfilment'] ?? []),
                'source' => $table ? 'qr' : 'customer_web',
                'payment_method' => $paymentMethod,
                'kot_release_policy' => (string) setting('online_order_kot_release_policy', 'after_payment_or_approval'),
                'customer_delivery_fee' => $deliveryQuote === null ? null : $deliveryFee,
                'delivery_distance_km' => $deliveryQuote['distance_km'] ?? null,
                'delivery_pricing_rule' => $deliveryQuote['pricing_rule'] ?? null,
                'additional_payments' => $deliveryFee > 0 ? ['customer_delivery_fee' => $deliveryFee] : null,
            ], static fn ($value) => $value !== null && $value !== '');

            /** @var Order $order */
            $order = Order::query()->create([
                'branch_id' => $branch->id,
                'customer_id' => $customer?->id,
                'table_id' => $table?->id,
                // A UPI order is intentionally not released to kitchen until
                // the signed gateway webhook verifies payment.
                'status' => $requiresStaffApproval ? OrderStatus::Pending : OrderStatus::Confirmed,
                'type' => $orderType,
                'payment_status' => OrderPaymentStatus::Unpaid,
                'currency' => $branch->currency,
                'currency_rate' => CurrencyRate::for($branch->currency),
                'subtotal' => Cart::subTotal()->amount(),
                'total' => $payableTotal,
                'due_amount' => $payableTotal,
                'guest_count' => $data['guest_count'] ?? 1,
                'notes' => $details['notes'],
                'fulfilment' => $details['fulfilment'],
                'kitchen_display' => ! $requiresStaffApproval,
                'order_date' => now(),
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'served_at' => $orderType === OrderType::DineIn ? now() : null,
            ]);

            foreach (Cart::items() as $item) {
                $order->updateOrCreateProduct($item);
            }

            $order->updateOrCreateTaxes(Cart::taxes());
            $order->updateOrCreateDiscount(Cart::discount());
            $order->syncApplicableOrderTaxes();
            if ($deliveryQuote !== null) {
                OrderDelivery::query()->create([
                    'tenant_id' => $customer->tenant_id, 'branch_id' => $branch->id, 'order_id' => $order->id,
                    'pickup_latitude' => $branch->latitude, 'pickup_longitude' => $branch->longitude,
                    'dropoff_latitude' => data_get($data, 'delivery_address.latitude'),
                    'dropoff_longitude' => data_get($data, 'delivery_address.longitude'),
                    'distance_km' => $deliveryQuote['distance_km'], 'customer_delivery_fee' => $deliveryFee,
                    'provider_quoted_cost' => $deliveryQuote['provider_cost'] ?? null,
                ]);
            }
            $order->storeStatusLog(OrderStatus::Pending);
            if (! $requiresStaffApproval) {
                $order->storeStatusLog(OrderStatus::Confirmed, note: 'QR_ORDER_SUBMITTED');
            }

            if ($wallet) {
                $balanceAfter = \Modules\Order\Delivery\DeliveryMoney::amount(
                    \Modules\Order\Delivery\DeliveryMoney::minor($wallet->balance, $branch->currency)
                    - \Modules\Order\Delivery\DeliveryMoney::minor($payableTotal, $branch->currency), $branch->currency);
                DB::table('customer_wallet_accounts')->where('id', $wallet->id)->update(['balance' => $balanceAfter, 'updated_at' => now()]);
                DB::table('customer_wallet_transactions')->insert([
                    'reference' => (string) Str::uuid(), 'tenant_id' => $customer->tenant_id, 'customer_id' => $customer->id,
                    'order_id' => $order->id, 'type' => 'order_payment', 'direction' => 'debit', 'amount' => $payableTotal,
                    'balance_after' => $balanceAfter, 'currency' => $branch->currency,
                    'idempotency_key' => $request->header('Idempotency-Key'), 'description' => 'Wallet payment for order '.$order->reference_no,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $order->storePayment([
                    'method' => PaymentMethod::MobileWallet->value,
                    'amount' => $payableTotal,
                    'transaction_id' => $request->header('Idempotency-Key'),
                    'meta' => ['source' => 'customer_wallet'],
                ]);
                $order->refreshDueAmount();
            }

            if ($table && $table->status !== TableStatus::Occupied) {
                $table->update(['status' => TableStatus::Occupied]);
                $table->storeStatusLog(
                    status: TableStatus::Occupied,
                    note: "QR_ORDER_CREATED:$order->reference_no"
                );
            }

            event(new OrderCreated($order, shouldPrintKitchenTicket: ! $requiresStaffApproval));

            return $order;
        });

        Cart::clear();

        return ApiResponse::created(
            body: [
                'order_id' => $order->reference_no,
                'reference_no' => $order->reference_no,
                'order_number' => $order->order_number,
                'delivery_fee' => $deliveryFee,
                'total' => $order->total->amount(),
            ],
            resource: $this->service->label()
        );
    }

    /**
     * Resolve a public dine-in table from a QR capability, or from an exact
     * visible table label in the already tenant-validated branch for the
     * direct online menu. Public callers can never submit a table primary key.
     */
    private function resolveAuthorizedPublicTable(Branch $branch, array $data): Table
    {
        if (filled($data['table_token'] ?? null)) {
            return Table::query()
                ->with(['activeOrders'])
                ->where('branch_id', $branch->id)
                ->where('uuid', $data['table_token'])
                ->where('is_active', true)
                ->firstOrFail();
        }

        if (filled($data['table_qr_payload'] ?? null)) {
            $decoded = $this->qrCodes->validateQRCode($data['table_qr_payload']);
            abort_unless(
                ($decoded['valid'] ?? false) === true
                && (int) ($decoded['branch_id'] ?? 0) === (int) $branch->id,
                422,
                'This table QR credential is invalid.'
            );

            return Table::query()
                ->with(['activeOrders'])
                ->where('branch_id', $branch->id)
                ->whereKey((int) ($decoded['table_id'] ?? 0))
                ->where('is_active', true)
                ->firstOrFail();
        }

        if (filled($data['table_number'] ?? null)) {
            $label = trim((string) $data['table_number']);

            return Table::query()
                ->with(['activeOrders'])
                ->where('branch_id', $branch->id)
                ->where('is_active', true)
                ->get()
                ->first(fn (Table $table) => mb_strtolower(trim((string) $table->name)) === mb_strtolower($label))
                ?? abort(422, 'The table number is not available at this restaurant.');
        }

        abort(422, 'Enter a valid table number or scan the table QR code.');
    }

    /**
     * This method update the provided data into storage for the Order model.
     *
     * @throws Throwable
     */
    public function update(SaveOrderRequest $request, string $cartId, string|int $id): JsonResponse
    {
        $order = $this->saveOrderService->update($id, [
            ...$request->validated(),
            'cart_id' => $cartId,
        ]);

        return ApiResponse::updated(
            body: ['order_id' => $order->reference_no, 'cart' => Cart::instance()],
            resource: $this->service->label()
        );
    }

    /**
     * Get add payment meta
     */
    public function getPaymentMeta(int|string $id): JsonResponse
    {
        return ApiResponse::success($this->paymentService->getPaymentMeta($id));
    }

    /**
     * Get update status meta
     */
    public function getUpdateStatusMeta(int|string $id): JsonResponse
    {
        return ApiResponse::success($this->service->getUpdateStatusMeta($id));
    }

    /**
     * Order add payment
     *
     * @throws Throwable
     */
    public function storePayment(OrderPaymentRequest $request, int|string $orderId): JsonResponse
    {
        $data = $request->validated();
        $slipPath = null;
        if ($request->hasFile('payment_slip')) {
            $slipPath = $request->file('payment_slip')->store('payment-slips/'.auth()->user()->tenant_id, 'local');
            $data['payments'][0]['meta']['payment_slip_path'] = $slipPath;
            $data['payments'][0]['meta']['payment_slip_name'] = $request->file('payment_slip')->getClientOriginalName();
        }
        try {
            $this->paymentService->storePayment($orderId, $data);
        } catch (Throwable $exception) {
            if ($slipPath) {
                Storage::disk('local')->delete($slipPath);
            }
            throw $exception;
        }

        return ApiResponse::success(message: __('order::messages.order_paid_successfully'));
    }

    public function paymentSlip(int|string $orderId, int $paymentId): StreamedResponse
    {
        $order = $this->service->findOrFail($orderId, true);
        $payment = Payment::query()->whereKey($paymentId)->where('order_id', $order->id)->firstOrFail();
        $path = data_get($payment->meta, 'payment_slip_path');
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, basename((string) data_get($payment->meta, 'payment_slip_name', 'payment-slip')));
    }

    /**
     * Finalize KOT
     */
    public function finalizeKOT(Request $request, int|string $orderId): JsonResponse
    {
        $order = Order::findOrFail($orderId);

        // Validate order is in appropriate state
        if ($order->payment_status !== OrderPaymentStatus::Paid) {
            return response()->json([
                'message' => 'Order must be paid before KOT finalization',
            ], 422);
        }

        DB::transaction(function () use ($order): void {
            $order->forceFill([
                'kot_finalized_at' => now(),
                'kot_finalized_by' => auth()->id(),
                'status' => OrderStatus::Completed,
                'closed_at' => $order->type === OrderType::DineIn ? now() : null,
            ])->save();

            event(new OrderUpdateStatus(
                order: $order,
                status: OrderStatus::Completed,
                changedById: auth()->id(),
            ));
        });

        return response()->json([
            'data' => [
                'order_id' => $order->id,
                'kot_finalized_at' => $order->kot_finalized_at,
                'status' => $order->status,
            ],
        ], 200);
    }

    /**
     * Fire a held course to the kitchen. Without `course_number`, fires the
     * next (lowest) held course. Idempotency middleware guards double-taps.
     */
    public function fireCourse(Request $request, int|string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'course_number' => 'nullable|integer|min:1|max:20',
        ]);

        $order = Order::findOrFail($orderId);

        $fired = DB::transaction(fn () => app(FireCourseService::class)->fire(
            $order,
            isset($validated['course_number']) ? (int) $validated['course_number'] : null,
        ));

        if ($fired === null) {
            return response()->json([
                'message' => __('order::messages.no_held_course_to_fire'),
            ], 422);
        }

        event(new OrderUpdated($order));

        return response()->json([
            'data' => [
                'order_id' => $order->id,
                'fired_course' => $fired,
            ],
        ]);
    }

    /**
     * Reprint Bill
     */
    public function reprintBill(Request $request, int|string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $order = Order::findOrFail($orderId);

        // Log reprint
        DB::table('bill_reprints')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'user_id' => auth()->id(),
            'reprint_count' => DB::table('bill_reprints')
                ->where('order_id', $order->id)
                ->count() + 1,
            'reason' => $validated['reason'] ?? 'User requested reprint',
            'created_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'order_id' => $order->id,
                'reprint_count' => DB::table('bill_reprints')
                    ->where('order_id', $order->id)
                    ->count(),
                'created_at' => now(),
            ],
        ], 200);
    }

    /**
     * Get active orders
     */
    public function activeOrders(Request $request): JsonResponse
    {
        $user = auth()->user();
        $branchId = $request->input('branch_id');
        $branch = $user->assignedToBranch()
            ? $user->branch
            : (! is_null($branchId) ? Branch::where('id', $branchId)->first() : $user->effective_branch);

        $orders = $this->service->activeOrders(
            $branch->id,
            $user->hasRole(DefaultRole::Waiter->value) ? $user->id : null
        );

        return ApiResponse::success(
            [
                'orders' => ActiveOrderResource::collection($orders->getCollection()),
                'pagination' => $this->paginationMeta($orders),
                'filters' => [
                    'statuses' => OrderStatus::toArrayTrans([
                        OrderStatus::Refunded->value,
                        OrderStatus::Cancelled->value,
                        OrderStatus::Merged->value,
                        OrderStatus::Served->value,
                    ]),
                    'order_types' => array_values(
                        array_filter(
                            OrderType::toArrayTrans(),
                            fn ($orderType) => in_array($orderType['id'], $branch->order_types)
                        )
                    ),
                    'payment_statuses' => OrderPaymentStatus::toArrayTrans(),
                ],
            ]
        );
    }

    /**
     * Get upcoming orders
     */
    public function upcomingOrders(Request $request): JsonResponse
    {
        $user = auth()->user();
        $branchId = $request->input('branch_id');
        $branch = $user->assignedToBranch()
            ? $user->branch
            : (! is_null($branchId) ? Branch::where('id', $branchId)->first() : $user->effective_branch);

        $orders = $this->service->upcomingOrders(
            $branch->id,
            $user->hasRole(DefaultRole::Waiter->value) ? $user->id : null
        );

        return ApiResponse::success(
            [
                'orders' => ActiveOrderResource::collection($orders->getCollection()),
                'pagination' => $this->paginationMeta($orders),
                'filters' => [
                    'statuses' => OrderStatus::toArrayTrans([
                        OrderStatus::Refunded->value,
                        OrderStatus::Cancelled->value,
                        OrderStatus::Merged->value,
                        OrderStatus::Completed->value,
                        OrderStatus::Served->value,
                        OrderStatus::Ready->value,
                        OrderStatus::Preparing->value,
                    ]),
                    'order_types' => array_values(
                        array_filter(
                            OrderType::toArrayTrans(),
                            fn ($orderType) => in_array($orderType['id'], [OrderType::Catering->value, OrderType::PreOrder->value]) && in_array($orderType['id'], $branch->order_types)
                        )
                    ),
                    'payment_statuses' => OrderPaymentStatus::toArrayTrans(),
                ],
            ]
        );
    }

    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'from' => $paginator->firstItem(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * Get customer orders for the authenticated customer user.
     */
    public function customerOrders(Request $request): JsonResponse
    {
        $customer = auth()->user();

        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->when($request->input('branch_id'), fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->with(['branch:id,name', 'table:id,name'])
            ->latest()
            ->paginate($request->input('per_page', 15));

        return ApiResponse::pagination(
            paginator: $orders,
            resource: OrderResource::class
        );
    }

    /**
     * Move to next status
     *
     * @throws Throwable
     */
    public function moveToNextStatus(int|string $orderId): JsonResponse
    {
        $newStatus = $this->service->moveToNextStatus($orderId);

        return ApiResponse::success(message: __('order::messages.order_update_status_to_successfully', ['status' => $newStatus->trans()]));
    }

    /**
     * Preview print
     *
     * @throws Throwable
     */
    public function previewPrint(Request $request, int|string $orderId, PrintContentType $type): JsonResponse
    {
        return ApiResponse::success($this->service->previewPrint($orderId, $type, $request->kitchen_id));
    }

    /**
     * Get print meta
     *
     * @throws Throwable
     */
    public function printMeta(Request $request, int|string $orderId): JsonResponse
    {
        return ApiResponse::success(
            $this->service->printMeta(
                $orderId,
                $request->input('branch_id'),
                $request->input('register_id'),
            )
        );
    }

    /**
     *  Print
     *
     * @throws Throwable
     */
    public function print(Request $request, int|string $orderId, PrintContentType $type): JsonResponse
    {
        $this->service->print($orderId, $type, $request->specific_id);

        return ApiResponse::success(message: __('order::messages.print_has_been_successfully'));
    }

    /**
     * Bulk update order statuses
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array'],
            'order_ids.*' => ['required', 'integer', 'exists:orders,id'],
            'status' => ['required', 'string', 'in:'.implode(',', OrderStatus::values())],
        ]);

        $nextStatus = OrderStatus::from($validated['status']);

        $updated = DB::transaction(function () use ($validated, $nextStatus): int {
            $updated = 0;
            $orders = Order::query()
                ->whereIn('id', $validated['order_ids'])
                ->lockForUpdate()
                ->get();

            foreach ($orders as $order) {
                if ($order->status === $nextStatus) {
                    continue;
                }

                abort_unless(
                    $order->allowUpdateStatus() && $order->next_status === $nextStatus,
                    422,
                    __('order::messages.could_not_update_order_status')
                );

                $order->update([
                    'status' => $nextStatus,
                    'closed_at' => $order->type === OrderType::DineIn && $nextStatus === OrderStatus::Completed
                        ? now()
                        : null,
                ]);

                event(new OrderUpdateStatus(
                    order: $order,
                    status: $nextStatus,
                    changedById: auth()->id(),
                ));

                $updated++;
            }

            return $updated;
        });

        return ApiResponse::success(
            body: ['updated_count' => $updated],
            message: __('order::messages.status_updated')
        );
    }
}
