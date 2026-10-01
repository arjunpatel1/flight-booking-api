<?php

namespace Modules\Aggregator\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Aggregator\Models\PartnerApiOrderMapping;
use Modules\Aggregator\Support\PartnerApiResponse;
use Modules\Aggregator\Support\PartnerContext;
use Modules\Order\Delivery\DeliveryReadModel;
use Modules\Order\Delivery\DeliveryStateMachine;
use Modules\Order\Enums\DeliveryStatus;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDelivery;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;

/** Driver-facing API for deliveries created by the same signed partner integration. */
final class PartnerDeliveryController
{
    public function __construct(private readonly EffectiveTenantEntitlementService $entitlements) {}

    public function show(Request $request, string $orderId): JsonResponse
    {
        if (! $this->deliveryEnabled($request)) {
            return PartnerApiResponse::error('DELIVERY_SERVICE_NOT_AVAILABLE', 'Third-party delivery is not active for this restaurant.', 403);
        }
        $mapping = $this->mapping($this->context($request), $orderId);
        if (! $this->ownsDelivery($mapping, $this->context($request))) {
            return PartnerApiResponse::error('DELIVERY_NOT_FOUND', 'Delivery task was not found.', 404);
        }
        $request->attributes->set('partner_branch_id', (int) $mapping->branch_id);

        return PartnerApiResponse::success($this->payload($mapping));
    }

    public function updateStatus(Request $request, string $orderId, DeliveryStateMachine $machine): JsonResponse
    {
        if (! $this->deliveryEnabled($request)) {
            return PartnerApiResponse::error('DELIVERY_SERVICE_NOT_AVAILABLE', 'Third-party delivery is not active for this restaurant.', 403);
        }
        $unknown = array_values(array_diff(array_keys($request->all()),
            ['status', 'rider_name', 'rider_phone', 'rider_vehicle', 'tracking_url', 'eta_minutes']));
        if ($unknown !== []) {
            return PartnerApiResponse::error('UNSUPPORTED_FIELDS', 'Send only documented delivery status fields.', 422, ['fields' => $unknown]);
        }
        $data = $request->validate([
            'status' => ['required', Rule::in([
                DeliveryStatus::RiderAssigned->value, DeliveryStatus::ArrivedAtPickup->value,
                DeliveryStatus::PickedUp->value, DeliveryStatus::InTransit->value,
                DeliveryStatus::ArrivedAtCustomer->value, DeliveryStatus::Delivered->value,
            ])],
            'rider_name' => ['nullable', 'string', 'max:120', 'required_if:status,rider_assigned'],
            'rider_phone' => ['nullable', 'string', 'max:40'],
            'rider_vehicle' => ['nullable', 'string', 'max:120'],
            'tracking_url' => ['nullable', 'url:https', 'max:2048'],
            'eta_minutes' => ['nullable', 'integer', 'between:1,1440'],
        ]);
        $context = $this->context($request);
        $mapping = $this->mapping($context, $orderId);
        if (! $this->ownsDelivery($mapping, $context)) {
            return PartnerApiResponse::error('DELIVERY_NOT_FOUND', 'Delivery task was not found.', 404);
        }
        $request->attributes->set('partner_branch_id', (int) $mapping->branch_id);
        $target = DeliveryStatus::from($data['status']);

        DB::transaction(function () use ($mapping, $context, $target, $data, $machine): void {
            $delivery = OrderDelivery::query()->withoutGlobalScopes()->whereKey($mapping->order->delivery->id)
                ->where('tenant_id', $context->tenantId())->lockForUpdate()->firstOrFail();
            abort_unless($delivery->mode === 'partner_api'
                && hash_equals((string) $delivery->external_partner_id, (string) $context->partner->uuid), 404);
            // A repeated partner milestone is a read-only replay. This also
            // permits a repeated delivered callback after the order completed.
            if ($delivery->status === $target) return;
            $order = Order::query()->withoutGlobalScopes()->whereKey($mapping->order_id)->lockForUpdate()->firstOrFail();
            abort_unless($order->payment_status === OrderPaymentStatus::Paid, 409,
                'Verify full order payment before assigning a delivery rider.');
            abort_unless(in_array($order->status, [OrderStatus::Preparing, OrderStatus::Ready,
                OrderStatus::OutForDelivery], true), 409,
                'The restaurant has not accepted this order for preparation.');
            if (in_array($target, [DeliveryStatus::PickedUp, DeliveryStatus::InTransit,
                DeliveryStatus::ArrivedAtCustomer, DeliveryStatus::Delivered], true)) {
                abort_unless(in_array($order->status, [OrderStatus::Ready, OrderStatus::OutForDelivery], true),
                    409, 'The restaurant has not marked this order ready for pickup.');
            }
            if ($target === DeliveryStatus::Delivered) {
                abort_unless($order->payment_status === OrderPaymentStatus::Paid, 409,
                    'Collect or verify the full order payment before marking delivery complete.');
            }
            $timestamps = match ($target) {
                DeliveryStatus::RiderAssigned => ['rider_assigned_at' => $delivery->rider_assigned_at ?: now(), 'assigned_at' => $delivery->assigned_at ?: now()],
                DeliveryStatus::ArrivedAtPickup => ['arrived_at_pickup_at' => $delivery->arrived_at_pickup_at ?: now()],
                DeliveryStatus::PickedUp => ['picked_up_at' => $delivery->picked_up_at ?: now()],
                DeliveryStatus::ArrivedAtCustomer => ['arrived_at_customer_at' => $delivery->arrived_at_customer_at ?: now()],
                DeliveryStatus::Delivered => ['delivered_at' => $delivery->delivered_at ?: now()],
                default => [],
            };
            $machine->transition($delivery, $target, [...$timestamps,
                'assignment_status' => 'partner_managed',
                'rider_name' => $data['rider_name'] ?? $delivery->rider_name,
                'rider_phone' => $data['rider_phone'] ?? $delivery->rider_phone,
                'rider_vehicle' => $data['rider_vehicle'] ?? $delivery->rider_vehicle,
                'tracking_url' => $data['tracking_url'] ?? $delivery->tracking_url,
                'eta_minutes' => $data['eta_minutes'] ?? $delivery->eta_minutes,
            ]);

            $orderTarget = $target === DeliveryStatus::Delivered ? OrderStatus::Completed
                : (in_array($target, [DeliveryStatus::PickedUp, DeliveryStatus::InTransit, DeliveryStatus::ArrivedAtCustomer], true)
                    ? OrderStatus::OutForDelivery : null);
            if ($orderTarget && $order->status !== $orderTarget) {
                $order->update(['status' => $orderTarget, 'closed_at' => $orderTarget === OrderStatus::Completed ? now() : null]);
                $order->storeStatusLog($orderTarget, note: 'PARTNER_DELIVERY_STATUS');
                event(new OrderUpdateStatus($order->fresh(), $orderTarget, note: 'PARTNER_DELIVERY_STATUS'));
            }
        }, 3);

        return PartnerApiResponse::success($this->payload($mapping->fresh('order.delivery')));
    }

    private function mapping(PartnerContext $context, string $uuid): ?PartnerApiOrderMapping
    {
        return PartnerApiOrderMapping::query()->where('partner_id', $context->partner->id)
            ->where('tenant_id', $context->tenantId())->where('uuid', $uuid)
            ->whereHas('order', fn ($query) => $query->whereColumn('orders.branch_id', 'partner_api_order_mappings.branch_id')
                ->whereHas('branch', fn ($branch) => $branch->withoutGlobalScopes()->where('tenant_id', $context->tenantId())))
            ->with('order.delivery')->first();
    }

    private function payload(PartnerApiOrderMapping $mapping): array
    {
        return ['order_id' => $mapping->uuid, 'order_reference' => $mapping->order?->reference_no,
            'delivery' => DeliveryReadModel::customer($mapping->order?->delivery)];
    }

    private function ownsDelivery(?PartnerApiOrderMapping $mapping, PartnerContext $context): bool
    {
        $delivery = $mapping?->order?->delivery;

        return $delivery !== null
            && (int) $delivery->tenant_id === $context->tenantId()
            && (int) $delivery->branch_id === (int) $mapping->branch_id
            && $delivery->mode === 'partner_api'
            && hash_equals((string) $delivery->external_partner_id, (string) $context->partner->uuid);
    }

    private function context(Request $request): PartnerContext
    {
        return $request->attributes->get('partner_context');
    }

    private function deliveryEnabled(Request $request): bool
    {
        $tenant = $request->attributes->get('tenant');

        return $tenant instanceof Tenant && $this->entitlements->has($tenant, 'delivery');
    }
}
