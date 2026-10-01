<?php

namespace Modules\Order\Delivery;

use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Saas\Support\TenantContext;

/** Builds the minimum Flash v1.3 createTask contract from a persisted order. */
final class UengageTaskPayload
{
    public function forOrder(string $orderReference, string $storeId): array
    {
        $order = Order::query()->withoutGlobalScopes()
            ->with(['branch' => fn ($query) => $query->withoutGlobalScopes(), 'customer', 'products.product'])
            ->where('reference_no', $orderReference)->first();
        $tenantId = app(TenantContext::class)->id();
        if (! $order || ! $order->branch || ! $tenantId
            || (int) $order->branch->tenant_id !== (int) $tenantId) {
            throw new ProviderUnavailable('ORDER_NOT_FOUND');
        }
        if ($order->type !== OrderType::Delivery || $order->payment_status !== OrderPaymentStatus::Paid) {
            throw new ProviderUnavailable('ORDER_NOT_ELIGIBLE');
        }

        $address = (array) data_get($order->fulfilmentDetails(), 'delivery_address', []);
        $pickup = $this->location([
            'name' => $order->branch->name,
            'contact_number' => $order->branch->phone,
            'latitude' => $order->branch->latitude,
            'longitude' => $order->branch->longitude,
            'address' => $this->address([$order->branch->address_line1, $order->branch->address_line2, $order->branch->postal_code]),
            'city' => $order->branch->city,
            'state' => $order->branch->state,
        ], 'PICKUP');
        $drop = $this->location([
            'name' => $address['recipient_name'] ?? $order->customer?->name,
            'contact_number' => $address['phone'] ?? $order->customer?->phone,
            'latitude' => $address['latitude'] ?? null,
            'longitude' => $address['longitude'] ?? null,
            'address' => $this->address([$address['address_line1'] ?? null, $address['address_line2'] ?? null,
                $address['landmark'] ?? null, $address['postal_code'] ?? null]),
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
        ], 'DROPOFF');

        return [
            'storeId' => $storeId,
            'order_details' => [
                'order_total' => (float) $order->total->amount(),
                'paid' => 'true',
                'vendor_order_id' => $order->reference_no,
                'order_source' => (string) (data_get($order->fulfilmentDetails(), 'channel')
                    ?: data_get($order->fulfilmentDetails(), 'source') ?: 'website'),
                'customer_orderId' => (string) ($order->order_number ?: $order->reference_no),
            ],
            'pickup_details' => $pickup,
            'drop_details' => $drop,
            'order_items' => $order->products->map(fn ($item) => [
                'id' => (string) ($item->product_id ?: $item->id),
                'name' => (string) $item->name,
                'quantity' => (int) $item->quantity,
                'price' => (float) $item->unit_price->amount(),
            ])->values()->all(),
        ];
    }

    private function location(array $data, string $prefix): array
    {
        $phone = preg_replace('/\D+/', '', (string) ($data['contact_number'] ?? ''));
        $required = ['name', 'latitude', 'longitude', 'address', 'city'];
        if (strlen($phone) < 7 || strlen($phone) > 20
            || collect($required)->contains(fn ($key) => blank($data[$key] ?? null))) {
            throw new ProviderUnavailable($prefix.'_DETAILS_INCOMPLETE');
        }

        return array_filter([
            'name' => trim((string) $data['name']),
            'contact_number' => $phone,
            'latitude' => (float) $data['latitude'],
            'longitude' => (float) $data['longitude'],
            'address' => trim((string) $data['address']),
            'city' => trim((string) $data['city']),
            'state' => filled($data['state'] ?? null) ? trim((string) $data['state']) : null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function address(array $parts): string
    {
        return collect($parts)->filter(fn ($part) => filled($part))->map(fn ($part) => trim((string) $part))->implode(', ');
    }
}
