<?php

namespace Modules\WhatsAppCenter\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Branch\Support\BranchSchedule;
use Modules\Cart\Cart as ServerCart;
use Modules\Cart\Storages\CartDBStorage;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderCreate\CreateOrderServiceInterface;
use Modules\Product\Models\Product;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Modules\Setting\Services\Delivery\DeliveryGeocoder;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;
use Modules\User\Services\Customer\CustomerServiceInterface;
use Modules\WhatsAppCenter\Data\WhatsAppChannelContext;
use Modules\WhatsAppCenter\Models\WhatsAppCatalogProduct;
use Modules\WhatsAppCenter\Models\WhatsAppConversation;
use Modules\WhatsAppCenter\Models\WhatsAppOrderSession;
use Modules\WhatsAppCenter\Support\WhatsAppOrderingPolicy;

class WhatsAppOrderingEngine
{
    public function __construct(
        private readonly BranchSchedule $branchSchedule,
        private readonly CreateOrderServiceInterface $orders,
        private readonly CustomerServiceInterface $customers,
        private readonly EffectiveTenantEntitlementService $entitlements,
    ) {}

    public function handle(WhatsAppConversation $conversation, string $text, WhatsAppChannelContext $context): string
    {
        abort_unless((int) $conversation->tenant_id === $context->tenantId
            && (int) $conversation->assignment_id === $context->assignmentId, 404, 'WhatsApp context is unavailable.');
        $branch = $this->resolveBranch($conversation, $context->allowedBranchIds);
        $capabilities = $this->capabilitiesForTenant($context->tenantId, $context->capabilities);
        $session = WhatsAppOrderSession::query()->withoutGlobalTenant()
            ->where('tenant_id', $conversation->tenant_id)->where('conversation_id', $conversation->id)
            ->whereNull('order_id')->where('expires_at', '>', now())->latest('id')->first();
        $session ??= WhatsAppOrderSession::query()->withoutGlobalTenant()->firstOrCreate(
            ['tenant_id' => $conversation->tenant_id, 'conversation_id' => $conversation->id, 'state' => 'browsing'],
            ['uuid' => (string) Str::uuid(), 'branch_id' => $branch->id, 'cart_uuid' => (string) Str::uuid(), 'order_type' => OrderType::Takeaway->value, 'expires_at' => now()->addHours(2)]
        );
        $cart = $this->cart($session);
        $cart->addBranch($branch);
        $cart->addOrderType(OrderType::from($session->order_type ?: OrderType::Takeaway->value));
        [$command, $argument] = array_pad(preg_split('/\s+/', trim(mb_strtolower($text)), 2) ?: [], 2, '');

        if (WhatsAppOrderingPolicy::isGreeting(trim($text), $capabilities)) {
            $session->update(['state' => 'awaiting_order_type', 'expires_at' => now()->addHours(2)]);

            return WhatsAppOrderingPolicy::orderTypePrompt((string) $branch->name, $capabilities);
        }

        if (str_starts_with((string) $session->state, 'waiting_delivery_') && ! in_array($command, ['human', 'agent', 'staff', 'pickup', 'takeaway', 'delivery'], true)) {
            return $this->handleDeliveryDetails($session, $branch, trim($text));
        }

        if ($session->state === 'awaiting_order_type' && ! in_array($command, ['pickup', 'takeaway', 'delivery', 'human', 'agent', 'staff'], true)) {
            return WhatsAppOrderingPolicy::orderTypePrompt((string) $branch->name, $capabilities);
        }

        return match ($command) {
            'help' => WhatsAppOrderingPolicy::catalogMessage((string) $branch->name, $capabilities),
            'menu' => $this->menu($branch),
            'add' => $this->add($cart, $branch, $argument, $session),
            'cart' => $this->summary($cart),
            'clear' => $this->clear($cart, $session),
            'pickup', 'takeaway' => $this->orderType($cart, $session, OrderType::Takeaway, $capabilities),
            'delivery' => $this->orderType($cart, $session, OrderType::Delivery, $capabilities),
            'checkout', 'confirm' => $this->checkout($cart, $session, $capabilities),
            'human', 'agent', 'staff' => $this->handoff($conversation),
            default => 'I did not understand that command. Send HELP to see the available ordering commands.',
        };
    }

    public function handleLocation(WhatsAppConversation $conversation, array $location, WhatsAppChannelContext $context): string
    {
        abort_unless((int) $conversation->tenant_id === $context->tenantId
            && (int) $conversation->assignment_id === $context->assignmentId, 404);
        $session = WhatsAppOrderSession::query()->withoutGlobalTenant()
            ->where('tenant_id', $context->tenantId)->where('conversation_id', $conversation->id)
            ->whereNull('order_id')->where('order_type', OrderType::Delivery->value)
            ->where('state', 'waiting_delivery_location')->where('expires_at', '>', now())
            ->latest('id')->first();
        abort_unless($session && $context->permitsBranch((int) $session->branch_id), 422,
            'Select DELIVERY in your active cart before sharing a location.');
        $point = \Modules\Order\Delivery\DeliveryLocation::fromAddress($location);
        abort_unless($point, 422, 'Please share a valid map location.');
        $previous = (array) $session->delivery_address;
        $resolved = [];
        try {
            $resolved = (array) (app(DeliveryGeocoder::class)->reverse($point->latitude, $point->longitude)[0] ?? []);
        } catch (\Throwable) {
            // A valid pin is enough to continue. The customer can complete the
            // human-readable address even when reverse geocoding is unavailable.
        }
        $recipient = trim((string) ($previous['recipient_name'] ?? $previous['recipient'] ?? $conversation->customer_name));
        if (in_array(mb_strtolower($recipient), ['', 'whatsapp customer'], true)) {
            $recipient = '';
        }
        $locality = collect([$resolved['road'] ?? null, $resolved['area'] ?? null])->filter()->unique()->join(', ');
        $address = [
            'latitude' => $point->latitude,
            'longitude' => $point->longitude,
            'location_name' => mb_substr(trim((string) ($location['name'] ?? '')), 0, 100),
            'location_address' => mb_substr(trim((string) ($location['address'] ?? '')), 0, 300),
            'formatted_address' => mb_substr(trim((string) ($resolved['label'] ?? $location['address'] ?? $location['name'] ?? '')), 0, 500),
            'address_line2' => mb_substr($locality, 0, 200),
            'city' => mb_substr(trim((string) ($resolved['city'] ?? '')), 0, 100),
            'state' => mb_substr(trim((string) ($resolved['state'] ?? '')), 0, 100),
            'postal_code' => mb_substr(trim((string) ($resolved['postal_code'] ?? '')), 0, 20),
            'recipient' => mb_substr($recipient, 0, 100),
            'recipient_name' => mb_substr($recipient, 0, 100),
            'phone' => $previous['phone'] ?? $conversation->customer_phone,
        ];
        $next = $recipient !== '' ? 'waiting_delivery_house' : 'waiting_delivery_customer_name';
        $session->update(['delivery_address' => $address, 'state' => $next]);
        $place = $address['formatted_address'] ?: 'the selected map pin';

        return $recipient !== ''
            ? "Location updated to {$place}. Now enter the house/flat number, building and street."
            : "Location selected: {$place}. Enter the customer name for this delivery.";
    }

    private function handleDeliveryDetails(WhatsAppOrderSession $session, Branch $branch, string $text): string
    {
        $address = (array) $session->delivery_address;
        $value = mb_substr(trim($text), 0, 200);
        $command = mb_strtolower(preg_replace('/\s+/', ' ', $value));
        if (in_array($command, ['change', 'change location', 'new location', 'restart address'], true)) {
            $session->update([
                'delivery_address' => $this->deliveryIdentity($address),
                'state' => 'waiting_delivery_location',
            ]);

            return 'Share the new delivery pin using the WhatsApp location button below. Your name and phone number are preserved.';
        }
        if ($session->state === 'waiting_delivery_location') {
            return 'Share your location using the WhatsApp location attachment. You can also send HUMAN for staff assistance.';
        }
        if ($session->state === 'waiting_delivery_confirmation') {
            abort_unless($command === 'confirm', 422, 'Reply CONFIRM to use this location or CHANGE LOCATION to select another.');
            try {
                $quote = $this->quoteDelivery($branch, $session, $this->cart($session));
            } catch (\Throwable $exception) {
                $session->update(['delivery_address' => $this->deliveryIdentity($address), 'state' => 'waiting_delivery_location']);
                report($exception);
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'delivery' => ['Home delivery is not available at this location right now. Choose Pickup, or choose Delivery to share a different location.'],
                ]);
            }
            $session->update(['state' => 'browsing']);

            $pricingLabel = $this->deliveryPricingLabel((string) ($quote['pricing_rule'] ?? ''));

            return sprintf(
                'Delivery is available (%.2f km from the restaurant). Delivery fee: %s %.2f%s. Your catalogue is ready below. Add items and tap Send to business.',
                $quote['distance_km'],
                $branch->currency ?: 'INR',
                $quote['delivery_fee'],
                $pricingLabel,
            );
        }
        if ($session->state === 'waiting_delivery_customer_name') {
            abort_unless($value !== '' && mb_strlen($value) >= 2, 422, 'Enter the customer name for this delivery.');
            $address['recipient'] = $value;
            $address['recipient_name'] = $value;
            $session->conversation()->update(['customer_name' => $value]);
            $next = 'waiting_delivery_house';
            $reply = 'Share your house/flat number and street address';
        } elseif ($session->state === 'waiting_delivery_house') {
            abort_unless($value !== '' && mb_strlen($value) >= 3, 422, 'Enter your house/flat number and street address.');
            $address['address_line1'] = $value;
            if (trim((string) ($address['city'] ?? '')) !== '') {
                $next = 'waiting_delivery_floor';
                $reply = "Address area found: {$address['city']}. Enter your floor or SKIP. Send CHANGE LOCATION at any time to choose another pin.";
            } else {
                $next = 'waiting_delivery_city';
                $reply = 'Enter your city. Send CHANGE LOCATION at any time to choose another pin.';
            }
        } elseif ($session->state === 'waiting_delivery_city') {
            abort_unless($value !== '' && mb_strlen($value) >= 2, 422, 'Enter your city.');
            $address['city'] = $value;
            $next = 'waiting_delivery_floor';
            $reply = 'Enter your floor or SKIP.';
        } elseif ($session->state === 'waiting_delivery_floor') {
            $address['floor'] = mb_strtolower($value) === 'skip' ? null : $value;
            $next = 'waiting_delivery_landmark';
            $reply = 'Enter a nearby landmark or SKIP.';
        } elseif ($session->state === 'waiting_delivery_landmark') {
            $address['landmark'] = mb_strtolower($value) === 'skip' ? null : $value;
            $session->update(['delivery_address' => $address, 'state' => 'waiting_delivery_confirmation']);

            return $this->deliveryConfirmationMessage($address);
        } else {
            abort(409, 'The delivery address step is unavailable.');
        }
        $session->update(['delivery_address' => $address, 'state' => $next]);

        return $reply;
    }

    public function importCatalogOrder(WhatsAppConversation $conversation, string $catalogId, array $items, WhatsAppChannelContext $context): WhatsAppOrderSession
    {
        abort_unless((int) $conversation->tenant_id === $context->tenantId
            && (int) $conversation->assignment_id === $context->assignmentId, 404, 'WhatsApp context is unavailable.');
        abort_if($catalogId === '' || $items === [], 422, 'The WhatsApp order is incomplete.');

        $branch = $this->resolveBranch($conversation, $context->allowedBranchIds);
        $retailerIds = collect($items)->pluck('product_retailer_id')->filter()->unique()->values();
        $mappings = WhatsAppCatalogProduct::query()->withoutGlobalTenant()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $branch->id)
            ->where('provider', $context->provider)
            ->where('catalog_id', $catalogId)
            ->where('status', 'active')
            ->whereIn('product_retailer_id', $retailerIds)
            ->get()->keyBy('product_retailer_id');
        if ($mappings->count() !== $retailerIds->count()) {
            $missingIds = $retailerIds->diff($mappings->keys())->values();
            $retired = WhatsAppCatalogProduct::query()->withoutGlobalTenant()
                ->with(['product' => fn ($query) => $query->withoutGlobalScopes()->withTrashed()])
                ->where('tenant_id', $context->tenantId)->where('branch_id', $branch->id)
                ->where('provider', $context->provider)->where('catalog_id', $catalogId)
                ->whereIn('product_retailer_id', $missingIds)->get();
            \Illuminate\Support\Facades\Log::warning('WhatsApp cart has retailer IDs absent from the active branch catalog.', [
                'tenant_id' => $context->tenantId, 'branch_id' => $branch->id,
                'catalog_id_hash' => hash('sha256', $catalogId),
                'missing_retailer_id_hashes' => $missingIds->map(fn ($id) => hash('sha256', (string) $id))->all(),
            ]);
            $names = $retired->map(fn ($mapping) => mb_substr(trim(strip_tags((string) $mapping->product?->name)), 0, 100))->filter()->unique()->values();
            if ($names->isNotEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['catalog_items' => $names->implode(', ').' is no longer available from this restaurant. Remove it from your cart and send the remaining items again.']);
            }
        }
        abort_unless($mappings->count() === $retailerIds->count(), 422, 'One or more WhatsApp catalog items are unavailable.');
        $products = Product::query()->withoutGlobalScopes()
            ->with(['options.values', 'files', 'categories', 'taxes', 'menu', 'branch'])
            ->whereIn('id', $mappings->pluck('product_id'))
            ->whereNull('deleted_at')
            ->where('is_active', true)->where('is_available', true)
            ->whereHas('menu', fn ($query) => $query->withoutGlobalScopes()
                ->where('menus.branch_id', $branch->id)->where('menus.is_active', true)->whereNull('menus.deleted_at'))
            ->get()->keyBy('id');
        if ($products->count() !== $mappings->pluck('product_id')->unique()->count()) {
            // A product may have been deleted or moved to another branch after
            // Meta cached it. Retire its mapping so a fresh catalog no longer
            // offers an item that checkout cannot safely fulfil.
            $mappings->filter(fn ($mapping) => ! $products->has($mapping->product_id))
                ->each(function ($mapping): void {
                    $mapping->update(['status' => 'disabled', 'sync_status' => 'pending']);
                    \Modules\WhatsAppCenter\Jobs\SyncWhatsAppCatalogProduct::dispatch($mapping->id)->afterCommit();
                });
            \Illuminate\Support\Facades\Log::warning('WhatsApp cart contains products no longer available in the branch menu.', [
                'tenant_id' => $context->tenantId, 'branch_id' => $branch->id,
                'missing_product_ids' => $mappings->pluck('product_id')->diff($products->keys())->values()->all(),
            ]);
            $names = Product::query()->withoutGlobalScopes()->withTrashed()
                ->whereIn('id', $mappings->pluck('product_id')->diff($products->keys()))->get()
                ->map(fn ($product) => mb_substr(trim(strip_tags((string) $product->name)), 0, 100))->filter()->unique()->values();
            if ($names->isNotEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['catalog_items' => $names->implode(', ').' is no longer available from this restaurant. Remove it from your cart and send the remaining items again.']);
            }
        }
        abort_unless($products->count() === $mappings->pluck('product_id')->unique()->count(), 422, 'One or more WhatsApp catalog items are unavailable.');

        $session = WhatsAppOrderSession::query()->withoutGlobalTenant()
            ->where('tenant_id', $conversation->tenant_id)->where('conversation_id', $conversation->id)
            ->whereNull('order_id')->where('state', 'browsing')->where('expires_at', '>', now())
            ->latest('id')->first();
        abort_unless($session, 422, 'Send HI, then choose DELIVERY or PICKUP before submitting your catalogue order.');
        abort_unless((int) $session->branch_id === (int) $branch->id, 422, 'The selected catalogue does not match your active branch.');
        $orderType = OrderType::from($session->order_type ?: OrderType::Takeaway->value);
        if ($orderType === OrderType::Delivery) {
            abort_unless($this->hasCompleteDeliveryAddress($session->delivery_address), 422,
                'Share and confirm your delivery location before submitting your catalogue order.');
        }
        if ($session->order_id) {
            return $session->load('order');
        }
        $cart = $this->cart($session);
        $cart->clear();
        $cart->addBranch($branch);
        $cart->addOrderType($orderType);
        foreach ($items as $item) {
            $mapping = $mappings->get((string) $item['product_retailer_id']);
            $product = $products->get($mapping?->product_id);
            abort_unless($product, 422, 'One or more WhatsApp catalog items are unavailable.');
            abort_if($product->options->isNotEmpty(), 422, 'An item requires choices and cannot be ordered from this catalog.');
            $cart->store($product->id, (int) $item['quantity'], loadedProduct: $product);
        }
        try {
            $session->update(['state' => 'waiting_approval', 'quoted_total' => $cart->total()->amount(), 'expires_at' => now()->addMinutes(30)]);
            $this->materializeOrder($session->fresh(), false);
            $mode = data_get($context->capabilities, 'approval_mode', 'manual');
            if (WhatsAppOrderingPolicy::releasesImmediately($context->capabilities)
                || $mode === 'automatic' || ($mode === 'hybrid' && $session->order_type === OrderType::Takeaway->value)) {
                $this->approve($session->fresh());
            }
        } catch (\Throwable $exception) {
            // Keep a valid provider cart retryable when quoting or order
            // validation fails after the products have been accepted.
            $session->refresh();
            if (! $session->order_id) {
                $session->update(['state' => 'browsing', 'expires_at' => now()->addHours(2)]);
            }
            throw $exception;
        }

        return $session->fresh()->load('order');
    }

    public function approve(WhatsAppOrderSession $session): Order
    {
        return DB::transaction(function () use ($session) {
            $session = WhatsAppOrderSession::query()->withoutGlobalTenant()->lockForUpdate()->findOrFail($session->id);
            if ($session->order_id) {
                $order = Order::query()->withOutGlobalBranchPermission()->lockForUpdate()->findOrFail($session->order_id);
                if ($order->status === OrderStatus::Pending) {
                    $order->update(['status' => OrderStatus::Confirmed, 'kitchen_display' => true]);
                    $order->storeStatusLog(OrderStatus::Confirmed, note: 'KITCHEN_RELEASED_AFTER_APPROVAL');
                    event(new OrderUpdateStatus($order->fresh(), OrderStatus::Confirmed, note: 'KITCHEN_RELEASED_AFTER_APPROVAL'));
                }
                $session->update(['state' => 'completed', 'confirmed_at' => now()]);

                return $order->fresh();
            }

            return $this->materializeOrder($session, true);
        }, 3);
    }

    private function materializeOrder(WhatsAppOrderSession $session, bool $releaseToKitchen): Order
    {
        abort_unless(in_array($session->state, ['waiting_approval', 'payment_pending'], true), 409, 'This WhatsApp cart is not awaiting approval.');
        $branch = Branch::query()->withoutGlobalActive()->where('tenant_id', $session->tenant_id)->findOrFail($session->branch_id);
        $availability = $this->branchSchedule->describe($branch);
        abort_unless($availability['is_open'], 422, $availability['opens_at'] ? "This branch is closed. It opens at {$availability['opens_at']}." : 'This branch is not accepting orders.');
        $cart = $this->cart($session);
        $cart->addBranch($branch);
        $type = OrderType::from($session->order_type ?: OrderType::Takeaway->value);
        if ($type === OrderType::Delivery) {
            abort_unless($this->hasCompleteDeliveryAddress($session->delivery_address), 422, 'Add the delivery address before approving this order.');
            $minimum = (float) ($branch->delivery_minimum_order ?? 0);
            abort_if($minimum > 0 && ((float) $cart->subTotal()->amount()) < $minimum, 422, "Delivery requires a minimum subtotal of {$minimum}.");
        }
        $cart->addOrderType($type);
        $cart->addTaxes();
        abort_if($cart->items()->isEmpty(), 422, 'The WhatsApp cart is empty.');

        $deliveryQuote = $type === OrderType::Delivery ? $this->quoteDelivery($branch, $session, $cart) : null;
        $deliveryFee = (float) ($deliveryQuote['delivery_fee'] ?? 0);

        $customer = $this->resolveCustomer($session->conversation, $branch);
        $order = $this->orders->createForActor([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'status' => $releaseToKitchen ? OrderStatus::Confirmed->value : OrderStatus::Pending->value,
            'type' => $type->value,
            'products' => $cart->items()->map(fn ($item) => [
                'id' => $item->product->id,
                'quantity' => (int) $item->qty,
                'options' => [],
            ])->values()->all(),
            'payment_methods' => [],
            'payments' => [],
            'guest_count' => 1,
            'notes' => 'WhatsApp catalog order',
            'additional_payments' => $deliveryFee > 0 ? ['customer_delivery_fee' => $deliveryFee] : [],
            'fulfilment' => [
                'source' => 'whatsapp',
                'channel' => 'whatsapp_catalog',
                'whatsapp_session_uuid' => $session->uuid,
                'kot_release_policy' => $releaseToKitchen ? 'immediate' : 'after_payment_or_approval',
                'delivery_address' => $type === OrderType::Delivery ? $session->delivery_address : null,
                'customer_delivery_fee' => $deliveryFee,
                'delivery_distance_km' => $deliveryQuote['distance_km'] ?? null,
                'delivery_pricing_rule' => $deliveryQuote['pricing_rule'] ?? null,
                'additional_payments' => $deliveryFee > 0 ? ['customer_delivery_fee' => $deliveryFee] : [],
            ],
            'kitchen_display' => $releaseToKitchen,
        ], $customer, trustedServerCharges: true);
        if ($deliveryQuote !== null) {
            \Modules\Order\Models\OrderDelivery::query()->create([
                'tenant_id' => $session->tenant_id, 'branch_id' => $branch->id, 'order_id' => $order->id,
                'pickup_latitude' => $branch->latitude, 'pickup_longitude' => $branch->longitude,
                'dropoff_latitude' => data_get($session->delivery_address, 'latitude'),
                'dropoff_longitude' => data_get($session->delivery_address, 'longitude'),
                'distance_km' => $deliveryQuote['distance_km'], 'customer_delivery_fee' => $deliveryFee,
            ]);
        }
        $session->update(['order_id' => $order->id, 'state' => $releaseToKitchen ? 'completed' : 'waiting_approval',
            'confirmed_at' => $releaseToKitchen ? now() : null, 'quoted_total' => $order->total->amount()]);
        $cart->clear();

        return $order;
    }

    private function cart(WhatsAppOrderSession $session): ServerCart
    {
        return new ServerCart(new CartDBStorage, app('events'), 'cart', "cart_{$session->cart_uuid}", config('cart.cart'));
    }

    private function resolveBranch(WhatsAppConversation $conversation, array $allowed): Branch
    {
        $query = Branch::query()->withoutGlobalActive()->where('tenant_id', $conversation->tenant_id)->where('is_active', true)->where('is_accepting_orders', true);
        if ($allowed !== []) {
            $query->whereIn('id', $allowed);
        }
        $branch = $conversation->branch_id ? (clone $query)->whereKey($conversation->branch_id)->first() : $query->orderByDesc('is_accepting_orders')->orderBy('id')->first();
        abort_unless($branch, 422, 'No WhatsApp ordering branch is available.');
        if (! $conversation->branch_id) {
            $conversation->update(['branch_id' => $branch->id]);
        }

        return $branch;
    }

    private function menu(Branch $branch): string
    {
        $products = $this->menuProducts($branch);
        if ($products->isEmpty()) {
            return 'No products are currently available.';
        }

        return "Available items:\n".$products->values()->map(fn ($product, $index) => ($index + 1).". {$product->name} — {$product->selling_price->format()}")->implode("\n")
            ."\nSend ADD <number> <qty>, for example ADD 2 1.";
    }

    private function add(ServerCart $cart, Branch $branch, string $argument, WhatsAppOrderSession $session): string
    {
        [$selectionKey, $qty] = array_pad(preg_split('/\s+/', trim($argument), 2) ?: [], 2, 1);
        $productId = $this->resolveTextMenuProductId($branch, (string) $selectionKey);
        abort_unless($productId, 422, 'Send MENU first, then reply ADD <number> <qty>, for example ADD 2 1.');
        $qty = max(1, min(99, (int) $qty));
        $product = Product::query()->with(['options.values', 'files', 'categories', 'taxes', 'menu', 'branch'])
            ->whereKey($productId)->where('is_active', true)->where('is_available', true)
            ->whereHas('menu', fn ($q) => $q->where('branch_id', $branch->id)->where('is_active', true)->whereNull('deleted_at'))->firstOrFail();
        abort_if($product->options->isNotEmpty(), 422, 'This item has choices. Ask staff to add it or use the online menu link.');
        $cart->store($product->id, $qty, loadedProduct: $product);
        $session->update(['expires_at' => now()->addHours(2), 'quoted_total' => $cart->total()->amount()]);

        return "Added {$qty} × {$product->name}.\n".$this->summary($cart);
    }

    private function menuProducts(Branch $branch)
    {
        return Product::query()->where('is_active', true)->where('is_available', true)
            ->whereHas('menu', fn ($query) => $query->where('branch_id', $branch->id)->where('is_active', true)->whereNull('deleted_at'))
            ->orderBy('display_priority')
            ->orderBy('name')
            ->limit(15)
            ->get();
    }

    private function resolveTextMenuProductId(Branch $branch, string $selectionKey): ?int
    {
        $selectionKey = trim($selectionKey);
        $selection = $this->menuProducts($branch)->values()
            ->mapWithKeys(fn ($product, $index) => [(string) ($index + 1) => $product->id])
            ->all();

        return isset($selection[$selectionKey]) ? (int) $selection[$selectionKey] : null;
    }

    private function summary(ServerCart $cart): string
    {
        if ($cart->items()->isEmpty()) {
            return 'Your cart is empty. Send MENU to browse.';
        }

        return "Cart:\n".$cart->items()->map(fn ($item) => "{$item->qty} × {$item->product->name}")->implode("\n")."\nTotal: {$cart->total()->format()}";
    }

    private function clear(ServerCart $cart, WhatsAppOrderSession $session): string
    {
        $cart->clear();
        $session->update(['quoted_total' => 0]);

        return 'Your WhatsApp cart is now empty.';
    }

    private function orderType(ServerCart $cart, WhatsAppOrderSession $session, OrderType $type, array $capabilities): string
    {
        if ($type === OrderType::Delivery) {
            abort_unless($this->tenantHasDelivery((int) $session->tenant_id), 403,
                'Delivery is not included in this restaurant subscription.');
        }
        $enabled = data_get($capabilities, 'enabled_order_types', ['takeaway']);
        abort_unless(in_array($type->value, $enabled, true), 422, ucfirst($type->value).' orders are not enabled for WhatsApp.');
        $branch = Branch::query()->withoutGlobalActive()->where('tenant_id', $session->tenant_id)->findOrFail($session->branch_id);
        abort_unless(collect($branch->order_types)->contains(fn ($available) => ($available instanceof OrderType ? $available->value : $available) === $type->value), 422, ucfirst($type->value).' is unavailable from this branch.');
        $cart->addOrderType($type);
        $session->update(['order_type' => $type->value, 'quoted_total' => $cart->total()->amount(),
            'state' => $type === OrderType::Delivery ? 'waiting_delivery_location' : 'browsing',
            'delivery_address' => null,
            'expires_at' => now()->addHours(2)]);

        return $type === OrderType::Delivery
            ? '📍 *Please Share Your Location*'
            : 'Pickup selected. Your catalogue is ready below. Add items and tap Send to business.';
    }

    private function capabilitiesForTenant(int $tenantId, array $capabilities): array
    {
        if ($this->tenantHasDelivery($tenantId)) {
            return $capabilities;
        }

        $capabilities['enabled_order_types'] = array_values(array_filter(
            (array) data_get($capabilities, 'enabled_order_types', ['takeaway']),
            fn ($type) => $type !== OrderType::Delivery->value,
        ));

        return $capabilities;
    }

    private function tenantHasDelivery(int $tenantId): bool
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->whereKey($tenantId)->where('is_active', true)->first();

        return $tenant && $this->entitlements->has($tenant, 'delivery');
    }

    private function checkout(ServerCart $cart, WhatsAppOrderSession $session, array $capabilities): string
    {
        abort_if($cart->items()->isEmpty(), 422, 'Add at least one item before checkout.');
        $branch = Branch::query()->withoutGlobalActive()->where('tenant_id', $session->tenant_id)->findOrFail($session->branch_id);
        $availability = $this->branchSchedule->describe($branch);
        abort_unless($availability['is_open'], 422, $availability['opens_at'] ? "This branch is closed. It opens at {$availability['opens_at']}." : 'This branch is not accepting orders.');
        if ($session->order_type === OrderType::Delivery->value) {
            abort_unless($this->hasCompleteDeliveryAddress($session->delivery_address), 422, 'Complete and confirm your delivery location before checkout. Send DELIVERY to start again.');
            $this->quoteDelivery($branch, $session, $cart);
        }
        $session->update(['state' => 'waiting_approval', 'quoted_total' => $cart->total()->amount(), 'expires_at' => now()->addMinutes(30)]);
        $mode = data_get($capabilities, 'approval_mode', 'manual');
        if ($mode === 'automatic' || ($mode === 'hybrid' && $session->order_type === OrderType::Takeaway->value)) {
            $order = $this->approve($session->fresh());

            return "Order {$order->reference_no} is confirmed. Total: {$order->total->format()}. The restaurant will send payment or fulfillment updates here.";
        }

        return $this->summary($cart)."\nYour order is waiting for restaurant approval. It is not confirmed or paid yet.";
    }

    private function quoteDelivery(Branch $branch, WhatsAppOrderSession $session, ServerCart $cart): array
    {
        app(\Modules\Order\Delivery\DeliveryAvailability::class)->assertAvailable($branch);
        abort_unless($this->hasCompleteDeliveryAddress($session->delivery_address), 422,
            'A complete delivery address is required.');
        abort_unless((bool) setting('delivery_enabled', false), 422,
            'Delivery is disabled in restaurant settings. Enable it before accepting WhatsApp delivery orders.');
        $calculator = app(\Modules\Order\Delivery\CustomerDeliveryQuote::class);
        $settings = $calculator->settings();
        $quote = $calculator->calculate($branch, (array) $session->delivery_address,
            (float) $cart->subTotal()->amount(), $settings);
        abort_unless($quote['serviceable'], 422, 'Home delivery is not available at this location.');
        try {
            $quote = app(\Modules\Order\Delivery\ThirdPartyCustomerPricing::class)->apply(
                $branch, (array) $session->delivery_address, $quote,
                'WHATSAPP-SERVICEABILITY-'.$session->uuid, true,
            );
        } catch (\Modules\Order\Delivery\ProviderUnavailable) {
            abort(503, 'Home delivery cannot be confirmed right now.');
        }
        abort_unless($quote['serviceable'], 422, $quote['message'] ?: 'Home delivery is not available at this location.');

        return $quote;
    }

    private function deliveryPricingLabel(string $pricingRule): string
    {
        if (preg_match('/^slab:([0-9.]+)-([0-9.]+)/', $pricingRule, $matches) === 1) {
            return sprintf(' (%g–%g km slab, including configured tax)', (float) $matches[1], (float) $matches[2]);
        }

        return $pricingRule === 'base_plus_started_km'
            ? ' (distance-based rate, including configured tax)'
            : '';
    }

    private function handoff(WhatsAppConversation $conversation): string
    {
        $conversation->update(['state' => 'waiting_for_staff']);

        return 'A restaurant team member will join this conversation shortly.';
    }

    private function deliveryIdentity(array $address): array
    {
        return array_filter([
            'recipient' => $address['recipient_name'] ?? $address['recipient'] ?? null,
            'recipient_name' => $address['recipient_name'] ?? $address['recipient'] ?? null,
            'phone' => $address['phone'] ?? null,
        ], fn ($value) => filled($value));
    }

    private function deliveryConfirmationMessage(array $address): string
    {
        $parts = collect([
            $address['address_line1'] ?? null,
            $address['address_line2'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['postal_code'] ?? null,
        ])->map(fn ($value) => trim((string) $value))->filter()->unique()->values();
        $extras = collect([
            filled($address['floor'] ?? null) ? 'Floor: '.$address['floor'] : null,
            filled($address['landmark'] ?? null) ? 'Landmark: '.$address['landmark'] : null,
        ])->filter();
        $summary = $parts->implode(', ');
        if ($extras->isNotEmpty()) {
            $summary .= "\n".$extras->implode(' · ');
        }

        return "📍 *Confirm delivery address*\n\nDeliver to: *{$address['recipient_name']}*\n{$summary}\n\nReply *CONFIRM* to check coverage and fee, or *CHANGE LOCATION* to choose another pin.";
    }

    private function hasCompleteDeliveryAddress(mixed $address): bool
    {
        return is_array($address)
            && trim((string) data_get($address, 'recipient')) !== ''
            && trim((string) data_get($address, 'phone')) !== ''
            && trim((string) data_get($address, 'address_line1')) !== ''
            && trim((string) data_get($address, 'city')) !== '';
    }

    private function resolveCustomer(WhatsAppConversation $conversation, Branch $branch): User
    {
        $phone = preg_replace('/\D+/', '', (string) $conversation->customer_phone);
        abort_unless(preg_match('/^[1-9][0-9]{9,14}$/', $phone), 422, 'The WhatsApp customer phone number is invalid.');

        $customer = User::query()->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') = ?", [$phone])
            ->whereHas('roles', fn ($query) => $query->where('name', DefaultRole::Customer->value))
            ->first();

        if ($customer) {
            if (! $customer->phone_verified_at) {
                $customer->forceFill(['phone_verified_at' => now()])->save();
            }

            return $customer;
        }

        return $this->customers->store([
            'tenant_id' => $conversation->tenant_id,
            'branch_id' => $branch->id,
            'name' => trim((string) $conversation->customer_name) ?: 'WhatsApp Customer',
            'phone' => $phone,
            'phone_country_iso_code' => setting('default_country_iso_code') ?: 'IN',
            'phone_verified_at' => now(),
            'username' => 'whatsapp_'.$conversation->tenant_id.'_'.Str::lower(Str::random(12)),
            'password' => bcrypt(Str::random(48)),
            'is_active' => true,
            'can_login' => true,
        ]);
    }
}
