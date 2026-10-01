<?php

namespace Modules\Order\Delivery;

use Modules\User\Models\CustomerAddress;
use Modules\User\Models\User;

final class DeliveryAddressResolver
{
    public function resolve(array $address, User $customer, int $tenantId): array
    {
        if (blank($address['id'] ?? null)) return $address;

        $saved = CustomerAddress::query()->where('tenant_id', $tenantId)
            ->where('user_id', $customer->id)->where('client_reference', $address['id'])->firstOrFail();
        return $saved->only(['label', 'recipient_name', 'phone', 'address_line1', 'address_line2',
            'city', 'postal_code', 'landmark', 'latitude', 'longitude']);
    }
}
