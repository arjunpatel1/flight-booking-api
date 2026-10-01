<?php

namespace Modules\Cart\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Cart\Cart as ServerCart;
use Modules\Cart\Events\CustomerGroupCartUpdated;
use Modules\Cart\Models\Cart as StoredCart;
use Modules\Cart\Models\CustomerGroupCart;
use Modules\Cart\Models\CustomerGroupCartEvent;
use Modules\Cart\Models\CustomerGroupCartItem;
use Modules\Cart\Models\CustomerGroupParticipant;
use Modules\Cart\Services\DiscountApplyService\DiscountApplyServiceInterface;
use Modules\Cart\Storages\CartDBStorage;
use Modules\Cart\Support\PublicTenantGuard;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Product\Models\Product;
use Modules\Saas\Support\CustomerAppContext;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;
use Symfony\Component\HttpFoundation\Response;

class CustomerGroupOrderService
{
    use \Modules\Cart\Traits\ValidatesCartItemOptions;

    private const ACTIVE = 'active';

    private const LOCKED = 'locked';

    private const CHECKOUT = 'checkout';

    private const COMPLETED = 'completed';

    private const EXPIRED = 'expired';

    private const CANCELLED = 'cancelled';

    public function create(Request $request, array $data): array
    {
        [$context, $customer] = $this->identity($request);
        $menu = filled($data['menu_reference'] ?? null)
            ? PublicTenantGuard::menu($request, (string) $data['menu_reference'])
            : null;
        $branch = Branch::query()->withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId())
            ->whereKey($menu?->branch_id ?? $data['branch_id'])
            ->firstOrFail();
        $token = Str::random(64);
        $code = strtoupper(Str::random(8));
        $group = DB::transaction(function () use ($context, $customer, $branch, $data, $token, $code) {
            $group = CustomerGroupCart::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $context->tenantId(),
                'branch_id' => $branch->getKey(),
                'host_customer_id' => $customer->getKey(),
                'cart_id' => (string) Str::uuid(),
                'status' => self::ACTIVE,
                'order_type' => $this->orderType($data['order_type'] ?? 'takeaway')->value,
                'invite_token_hash' => hash('sha256', $token),
                'invite_code_hash' => hash('sha256', $code),
                'version' => 1,
                'participant_limit' => min(20, max(2, (int) ($data['participant_limit'] ?? 12))),
                'expires_at' => now()->addMinutes(min(240, max(15, (int) ($data['expires_in_minutes'] ?? 90)))),
            ]);
            CustomerGroupParticipant::query()->create([
                'group_cart_id' => $group->id,
                'tenant_id' => $group->tenant_id,
                'customer_id' => $customer->getKey(),
                'role' => 'host',
                'status' => 'active',
                'joined_at' => now(),
            ]);
            $this->event($group, $customer, 'created');

            return $group;
        });

        $this->rebuildCart($group);

        return $this->present($group, $customer, ['invite_token' => $token, 'invite_code' => $code]);
    }

    public function resolveInvite(Request $request, string $token): array
    {
        [$context, $customer] = $this->identity($request);
        $group = CustomerGroupCart::query()->where('tenant_id', $context->tenantId())
            ->where(fn ($query) => $query
                ->where('invite_token_hash', hash('sha256', $token))
                ->orWhere('invite_code_hash', hash('sha256', strtoupper($token))))
            ->firstOrFail();
        $this->assertAvailable($group, joinable: true);

        return $this->present($group, $customer);
    }

    public function join(Request $request, string $token): array
    {
        [$context, $customer] = $this->identity($request);
        $group = DB::transaction(function () use ($context, $customer, $token) {
            $group = CustomerGroupCart::query()->where('tenant_id', $context->tenantId())
                ->where(fn ($query) => $query
                    ->where('invite_token_hash', hash('sha256', $token))
                    ->orWhere('invite_code_hash', hash('sha256', strtoupper($token))))
                ->lockForUpdate()->firstOrFail();
            $this->assertAvailable($group, joinable: true);
            $existing = CustomerGroupParticipant::query()
                ->where('group_cart_id', $group->id)->where('customer_id', $customer->id)->first();
            if ($existing?->status === 'active') {
                return $group;
            }
            $count = CustomerGroupParticipant::query()->where('group_cart_id', $group->id)
                ->where('status', 'active')->lockForUpdate()->count();
            abort_if($count >= $group->participant_limit, 409, 'This group order is full.');
            CustomerGroupParticipant::query()->updateOrCreate(
                ['group_cart_id' => $group->id, 'customer_id' => $customer->id],
                ['tenant_id' => $group->tenant_id, 'role' => 'participant', 'status' => 'active', 'joined_at' => now(), 'left_at' => null],
            );
            $group->increment('version');
            $this->event($group, $customer, 'participant_joined');

            return $group->refresh();
        });

        return $this->present($group, $customer);
    }

    public function show(Request $request, string $groupId): array
    {
        [, $customer] = $this->identity($request);
        $group = $this->groupForCustomer($request, $groupId, $customer);
        $this->expireIfNeeded($group);

        return $this->present($group->refresh(), $customer);
    }

    public function addItem(Request $request, string $groupId, array $data): array
    {
        [, $customer] = $this->identity($request);
        $group = DB::transaction(function () use ($request, $groupId, $customer, $data) {
            $group = $this->lockedGroup($request, $groupId, $customer, $data['version']);
            $participant = $this->activeParticipant($group, $customer);
            $product = $this->groupProduct(
                $group,
                isset($data['product_reference']) ? (string) $data['product_reference'] : null,
                isset($data['product_id']) ? (int) $data['product_id'] : null,
            );
            $data['options'] = $this->normalizeCartItemOptionReferences($product, $data['options'] ?? []);
            $unitPrice = $this->validateAndPrice($group, $data, $product);
            CustomerGroupCartItem::query()->create([
                'id' => (string) Str::uuid(), 'group_cart_id' => $group->id, 'tenant_id' => $group->tenant_id,
                'participant_id' => $participant->id, 'product_id' => $product->id,
                'quantity' => $data['quantity'], 'options' => $data['options'] ?? [],
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'unit_price_snapshot' => $unitPrice, 'cart_version' => $group->version + 1,
            ]);
            $group->increment('version');
            $this->rebuildCart($group->refresh());
            $this->event($group, $customer, 'item_added', ['product_reference' => $product->uuid]);

            return $group->refresh();
        });

        return $this->present($group, $customer);
    }

    public function updateItem(Request $request, string $groupId, string $itemId, array $data): array
    {
        [, $customer] = $this->identity($request);
        $group = DB::transaction(function () use ($request, $groupId, $itemId, $customer, $data) {
            $group = $this->lockedGroup($request, $groupId, $customer, $data['version']);
            $participant = $this->activeParticipant($group, $customer);
            $item = CustomerGroupCartItem::query()->where('tenant_id', $group->tenant_id)
                ->where('group_cart_id', $group->id)->where('participant_id', $participant->id)
                ->whereKey($itemId)->lockForUpdate()->firstOrFail();
            $payload = ['product_id' => $item->product_id, 'quantity' => $data['quantity'], 'options' => $data['options'] ?? $item->options];
            $product = $this->groupProduct($group, (string) $item->product?->uuid);
            $item->update([
                'quantity' => $data['quantity'], 'options' => $payload['options'],
                'note' => array_key_exists('note', $data) ? (filled($data['note']) ? trim($data['note']) : null) : $item->note,
                'unit_price_snapshot' => $this->validateAndPrice($group, $payload, $product), 'cart_version' => $group->version + 1,
            ]);
            $group->increment('version');
            $this->rebuildCart($group->refresh());
            $this->event($group, $customer, 'item_updated', ['item_id' => $item->id]);

            return $group->refresh();
        });

        return $this->present($group, $customer);
    }

    public function removeItem(Request $request, string $groupId, string $itemId, int $version): array
    {
        [, $customer] = $this->identity($request);
        $group = DB::transaction(function () use ($request, $groupId, $itemId, $customer, $version) {
            $group = $this->lockedGroup($request, $groupId, $customer, $version);
            $participant = $this->activeParticipant($group, $customer);
            $item = CustomerGroupCartItem::query()->where('tenant_id', $group->tenant_id)
                ->where('group_cart_id', $group->id)->where('participant_id', $participant->id)
                ->whereKey($itemId)->lockForUpdate()->firstOrFail();
            $item->delete();
            $group->increment('version');
            $this->rebuildCart($group->refresh());
            $this->event($group, $customer, 'item_removed', ['item_id' => $itemId]);

            return $group->refresh();
        });

        return $this->present($group, $customer);
    }

    public function removeParticipant(Request $request, string $groupId, string $participantReference, int $version): array
    {
        [, $customer] = $this->identity($request);
        $group = DB::transaction(function () use ($request, $groupId, $participantReference, $customer, $version) {
            $group = $this->lockedGroup($request, $groupId, $customer, $version, host: true);
            $participant = CustomerGroupParticipant::query()->where('tenant_id', $group->tenant_id)
                ->where('group_cart_id', $group->id)->where('uuid', $participantReference)->lockForUpdate()->firstOrFail();
            abort_if($participant->role === 'host', 422, 'The host cannot be removed from the group.');
            $participant->items()->delete();
            $participant->update(['status' => 'removed', 'left_at' => now()]);
            $group->increment('version');
            $this->rebuildCart($group->refresh());
            $this->event($group, $customer, 'participant_removed', ['participant_id' => $participant->id]);

            return $group->refresh();
        });

        return $this->present($group, $customer);
    }

    public function cancel(Request $request, string $groupId, int $version): array
    {
        [, $customer] = $this->identity($request);
        $group = DB::transaction(function () use ($request, $groupId, $customer, $version) {
            $group = $this->groupForCustomer($request, $groupId, $customer, lock: true);
            $this->assertHost($group, $customer);
            $this->assertVersion($group, $version);
            abort_unless(in_array($group->status, [self::ACTIVE, self::LOCKED], true), 409, 'This group can no longer be cancelled.');
            $group->update(['status' => self::CANCELLED, 'version' => $group->version + 1]);
            $this->event($group, $customer, 'cancelled');

            return $group->refresh();
        });

        return $this->present($group, $customer);
    }

    public function lock(Request $request, string $groupId, array $data): array
    {
        [, $customer] = $this->identity($request);
        $group = DB::transaction(function () use ($request, $groupId, $customer, $data) {
            $group = $this->lockedGroup($request, $groupId, $customer, $data['version'], host: true);
            abort_if($group->items()->count() === 0, 422, 'Add at least one item before locking the group.');
            $group->update(['status' => self::LOCKED, 'locked_at' => now(), 'version' => $group->version + 1]);
            $this->rebuildCart($group->refresh());
            $this->event($group, $customer, 'locked');

            return $group->refresh();
        });

        return $this->present($group, $customer);
    }

    public function prepareCheckout(Request $request, string $groupId, int $version): array
    {
        [, $customer] = $this->identity($request);
        $group = DB::transaction(function () use ($request, $groupId, $customer, $version) {
            $group = $this->groupForCustomer($request, $groupId, $customer, lock: true);
            $this->assertHost($group, $customer);
            if ($group->status === self::CHECKOUT) {
                return $group;
            }
            $this->assertVersion($group, $version);
            abort_unless($group->status === self::LOCKED, 409, 'Lock the group before checkout.');
            $this->assertBranchOrderingAvailable($group);
            $group->update([
                'status' => self::CHECKOUT,
                'quote_snapshot' => $this->quote($group),
                'version' => $group->version + 1,
            ]);
            $this->event($group, $customer, 'checkout_started');

            return $group->refresh();
        });

        return $this->present($group, $customer, ['checkout_cart_id' => $group->cart_id]);
    }

    public function applyCoupon(Request $request, string $groupId, int $version, string $code): array
    {
        [, $customer] = $this->identity($request);
        $group = DB::transaction(function () use ($request, $groupId, $customer, $version, $code) {
            $group = $this->groupForCustomer($request, $groupId, $customer, lock: true);
            $this->assertHost($group, $customer);
            $this->assertVersion($group, $version);
            abort_unless($group->status === self::LOCKED, 409, 'Lock the group before applying a coupon.');
            $cart = $this->cart($group->cart_id);
            $cart->addCustomer($customer);
            app(DiscountApplyServiceInterface::class)->applyVoucher($cart, trim($code));
            $cart->addTaxes();
            $group->increment('version');
            $this->event($group, $customer, 'coupon_applied');

            return $group->refresh();
        });

        return $this->present($group, $customer);
    }

    public function removeCoupon(Request $request, string $groupId, int $version): array
    {
        [, $customer] = $this->identity($request);
        $group = DB::transaction(function () use ($request, $groupId, $customer, $version) {
            $group = $this->groupForCustomer($request, $groupId, $customer, lock: true);
            $this->assertHost($group, $customer);
            $this->assertVersion($group, $version);
            abort_unless($group->status === self::LOCKED, 409, 'The group is not ready for checkout.');
            $cart = $this->cart($group->cart_id);
            $cart->removeDiscount();
            $cart->addTaxes();
            $group->increment('version');
            $this->event($group, $customer, 'coupon_removed');

            return $group->refresh();
        });

        return $this->present($group, $customer);
    }

    public function complete(Request $request, string $groupId, string $reference): array
    {
        [, $customer] = $this->identity($request);
        $group = DB::transaction(function () use ($request, $groupId, $customer, $reference) {
            $group = $this->groupForCustomer($request, $groupId, $customer, lock: true);
            $this->assertHost($group, $customer);
            if ($group->status === self::COMPLETED) {
                return $group;
            }
            abort_unless($group->status === self::CHECKOUT, 409, 'Group checkout has not started.');
            $order = Order::query()->withoutGlobalScopes()->where('branch_id', $group->branch_id)
                ->where('customer_id', $customer->id)
                ->whereHas('branch', fn ($query) => $query->where('tenant_id', $group->tenant_id))
                ->where(fn ($query) => $query->where('reference_no', $reference)->orWhereKey($reference))->firstOrFail();
            $group->update(['status' => self::COMPLETED, 'order_id' => $order->id, 'completed_at' => now(), 'version' => $group->version + 1]);
            $this->event($group, $customer, 'completed', ['order_id' => $order->id]);

            return $group->refresh();
        });

        return $this->present($group, $customer);
    }

    private function identity(Request $request): array
    {
        $context = $request->attributes->get(CustomerAppContext::class) ?? app(CustomerAppContext::class);
        $customer = $request->user();
        abort_unless($customer instanceof User && $customer->hasRole(DefaultRole::Customer->value)
            && (int) $customer->tenant_id === $context->tenantId(), Response::HTTP_FORBIDDEN, 'Customer access is required.');

        return [$context, $customer];
    }

    private function groupForCustomer(Request $request, string $id, User $customer, bool $lock = false): CustomerGroupCart
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $query = CustomerGroupCart::query()->where('tenant_id', $tenantId)->whereKey($id)
            ->whereHas('participants', fn ($query) => $query->where('customer_id', $customer->id)->where('status', 'active'));

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function lockedGroup(Request $request, string $id, User $customer, int $version, bool $host = false): CustomerGroupCart
    {
        $group = $this->groupForCustomer($request, $id, $customer, lock: true);
        $this->assertAvailable($group);
        $this->assertVersion($group, $version);
        if ($host) {
            $this->assertHost($group, $customer);
        }

        return $group;
    }

    private function activeParticipant(CustomerGroupCart $group, User $customer): CustomerGroupParticipant
    {
        return CustomerGroupParticipant::query()->where('group_cart_id', $group->id)
            ->where('customer_id', $customer->id)->where('status', 'active')->firstOrFail();
    }

    private function assertHost(CustomerGroupCart $group, User $customer): void
    {
        abort_unless((int) $group->host_customer_id === (int) $customer->id, 403, 'Only the group host can do this.');
    }

    private function assertVersion(CustomerGroupCart $group, int $version): void
    {
        if ((int) $group->version !== $version) {
            throw ValidationException::withMessages(['version' => 'Someone updated this group. Refresh and try again.']);
        }
    }

    private function assertAvailable(CustomerGroupCart $group, bool $joinable = false): void
    {
        $this->expireIfNeeded($group);
        abort_if(in_array($group->status, [self::EXPIRED, self::CANCELLED, self::COMPLETED], true), 410, 'This group order is no longer active.');
        if ($joinable) {
            abort_unless($group->status === self::ACTIVE, 409, 'This group order is not accepting participants.');
        } else {
            abort_unless($group->status === self::ACTIVE, 409, 'The group is locked. Refresh to see the latest status.');
        }
    }

    private function expireIfNeeded(CustomerGroupCart $group): void
    {
        if ($group->expires_at->isPast() && ! in_array($group->status, [self::COMPLETED, self::CANCELLED, self::EXPIRED], true)) {
            $group->forceFill(['status' => self::EXPIRED, 'version' => $group->version + 1])->save();
            $this->event($group, null, 'expired');
        }
    }

    private function groupProduct(CustomerGroupCart $group, ?string $reference, ?int $legacyId = null): Product
    {
        return Product::query()->with(['files', 'categories', 'taxes', 'menu', 'branch', 'options.values'])
            ->when($reference !== null && $reference !== '',
                fn ($query) => $query->where('uuid', $reference),
                fn ($query) => $query->whereKey($legacyId),
            )
            ->where('is_active', true)
            ->whereHas('menu', fn ($query) => $query->where('branch_id', $group->branch_id)->where('is_active', true))->firstOrFail();
    }

    private function validateAndPrice(CustomerGroupCart $group, array $data, Product $product): float
    {
        $tempId = (string) Str::uuid();
        $cart = $this->cart($tempId);
        try {
            $cart->clear();
            $cart->addBranch($group->branch);
            $cart->addOrderType($this->orderType($group->order_type));
            $cart->store($product->id, 1, $data['options'] ?? [], loadedProduct: $product);

            return (float) $cart->items()->first()->unitPrice()->amount();
        } finally {
            StoredCart::query()->whereKey("cart_{$tempId}")->delete();
        }
    }

    private function rebuildCart(CustomerGroupCart $group): ServerCart
    {
        $group->loadMissing('branch', 'items.product');
        $cart = $this->cart($group->cart_id);
        $cart->clear();
        $cart->addBranch($group->branch);
        $cart->addOrderType($this->orderType($group->order_type));
        foreach ($group->items as $item) {
            $cart->store($item->product_id, $item->quantity, $item->options ?? [], loadedProduct: $item->product);
        }
        $cart->addTaxes();

        return $cart;
    }

    private function cart(string $cartId): ServerCart
    {
        return new ServerCart(new CartDBStorage, app('events'), 'cart', "cart_{$cartId}", config('cart.cart'));
    }

    private function quote(CustomerGroupCart $group): array
    {
        $cart = $this->cart($group->cart_id);

        return [
            'currency' => $group->branch->currency, 'item_total' => $cart->subTotal()->amount(),
            'discount' => $cart->discount()->value()->amount(), 'tax' => $cart->tax()->amount(), 'total' => $cart->total()->amount(),
        ];
    }

    private function present(CustomerGroupCart $group, User $viewer, array $extra = []): array
    {
        $group->load([
            'branch:id,name,tenant_id,currency',
            'host:id,name',
            'participants.customer:id,name',
            'items.participant:id,uuid',
            'items.product:id,uuid,name,menu_id',
        ]);
        $participant = $group->participants->firstWhere('customer_id', $viewer->id);

        return array_merge([
            'id' => $group->id, 'status' => $group->status, 'version' => $group->version,
            'order_type' => $group->order_type,
            'expires_at' => $group->expires_at?->toIso8601String(), 'is_host' => (int) $group->host_customer_id === (int) $viewer->id,
            'restaurant' => ['name' => $group->branch?->name],
            'host' => ['name' => $group->host?->name],
            'participants' => $group->participants->where('status', 'active')->map(fn ($member) => [
                'reference' => $member->uuid, 'name' => $member->customer?->name, 'role' => $member->role,
                'is_me' => (int) $member->customer_id === (int) $viewer->id,
                'item_count' => $group->items->where('participant_id', $member->id)->sum('quantity'),
            ])->values(),
            'items' => $group->items->map(fn ($item) => [
                'id' => $item->id, 'participant_reference' => $item->participant?->uuid,
                'is_mine' => (int) $item->participant_id === (int) $participant?->id,
                'product_reference' => $item->product?->uuid, 'name' => $item->product?->name,
                'quantity' => $item->quantity, 'options' => $item->options ?? [], 'note' => $item->note,
                'unit_price' => (float) $item->unit_price_snapshot,
                'line_total' => round((float) $item->unit_price_snapshot * $item->quantity, 2),
            ])->values(),
            'quote' => in_array($group->status, [self::CHECKOUT, self::COMPLETED], true) && $group->quote_snapshot
                ? $group->quote_snapshot
                : $this->quote($group),
            'realtime' => $this->realtimeConfig($group),
        ], $extra);
    }

    private function realtimeConfig(CustomerGroupCart $group): array
    {
        $key = (string) config('broadcasting.connections.reverb.key', '');
        $options = (array) config('broadcasting.connections.reverb.options', []);
        $host = (string) ($options['host'] ?? '');
        $scheme = (string) ($options['scheme'] ?? 'https');
        $port = (int) ($options['port'] ?? ($scheme === 'https' ? 443 : 80));
        $localHosts = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];
        if (in_array(strtolower($host), $localHosts, true)) {
            $publicApi = (string) config('saas.self_service.public_api_base_url', config('app.url'));
            $publicHost = parse_url($publicApi, PHP_URL_HOST);
            if (is_string($publicHost) && $publicHost !== '') {
                $host = $publicHost;
                $scheme = 'https';
                $port = 443;
            }
        }

        return [
            'enabled' => $key !== '' && $host !== '',
            'app_key' => $key,
            'host' => $host,
            'port' => $port,
            'scheme' => $scheme,
            'channel' => "private-customer-group.tenant.{$group->tenant_id}.group.{$group->id}",
            'event' => 'customer.group.updated',
            'auth_path' => '/customer-app/group-orders/broadcasting/auth',
        ];
    }

    private function orderType(string $value): OrderType
    {
        return OrderType::tryFrom($value) ?? OrderType::Takeaway;
    }

    private function assertBranchOrderingAvailable(CustomerGroupCart $group): void
    {
        abort_unless($group->branch()->withoutGlobalScopes()->where('tenant_id', $group->tenant_id)->where('is_active', true)->exists(), 409, 'Restaurant is not accepting orders.');
    }

    private function event(CustomerGroupCart $group, ?User $actor, string $event, array $metadata = []): void
    {
        CustomerGroupCartEvent::query()->create([
            'group_cart_id' => $group->id, 'tenant_id' => $group->tenant_id,
            'actor_customer_id' => $actor?->id, 'event' => $event,
            'metadata' => $metadata ?: null, 'created_at' => now(),
        ]);
        event(new CustomerGroupCartUpdated($group->fresh(), $event, $actor?->id, $metadata));
    }
}
