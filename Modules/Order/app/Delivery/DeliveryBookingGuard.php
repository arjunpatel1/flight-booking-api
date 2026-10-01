<?php

namespace Modules\Order\Delivery;

use Illuminate\Support\Facades\DB;
use Modules\Order\Enums\DeliveryStatus;
use Modules\Order\Models\OrderDelivery;

final class DeliveryBookingGuard
{
    /** Atomically claims the only permitted provider booking attempt for an order. */
    public function claim(int $tenantId, int $orderId, string $assignmentToken, string $correlationId): bool
    {
        return DB::transaction(function () use ($tenantId, $orderId, $assignmentToken, $correlationId): bool {
            $delivery = OrderDelivery::query()->where('tenant_id', $tenantId)->where('order_id', $orderId)->lockForUpdate()->first();
            if (! $delivery || $delivery->assignment_token !== $assignmentToken
                || $delivery->external_delivery_id || $delivery->provider_correlation_id
                || ! in_array($delivery->status, [DeliveryStatus::Assigning, DeliveryStatus::Quoted, DeliveryStatus::ServiceabilityChecked], true)) {
                return false;
            }
            $delivery->fill([
                'status' => DeliveryStatus::BookingPending,
                'assignment_status' => 'booking_pending',
                'provider_correlation_id' => $correlationId,
                'booking_phase' => 'claimed',
                'booking_claimed_at' => now(),
            ])->save();
            return true;
        }, 3);
    }

    /**
     * Commits the point after which provider transmission may have occurred.
     * Recovery must treat this phase as ambiguous and must never auto-book again.
     */
    public function markRequestStarting(int $tenantId, int $orderId, string $assignmentToken, string $correlationId): bool
    {
        return DB::transaction(function () use ($tenantId, $orderId, $assignmentToken, $correlationId): bool {
            $delivery = OrderDelivery::query()->where('tenant_id', $tenantId)->where('order_id', $orderId)->lockForUpdate()->first();
            if (! $delivery || $delivery->assignment_token !== $assignmentToken
                || $delivery->provider_correlation_id !== $correlationId
                || $delivery->status !== DeliveryStatus::BookingPending
                || $delivery->booking_phase !== 'claimed'
                || $delivery->external_delivery_id) {
                return false;
            }

            $delivery->fill([
                'booking_phase' => 'request_starting',
                'booking_requested_at' => now(),
            ])->save();

            return true;
        }, 3);
    }
    /** Release a local claim only when no provider request has started. */
    public function releasePreTransmissionClaim(int $tenantId, int $orderId, string $assignmentToken, string $correlationId): bool
    {
        return DB::transaction(function () use ($tenantId, $orderId, $assignmentToken, $correlationId): bool {
            $delivery = OrderDelivery::query()->where('tenant_id', $tenantId)->where('order_id', $orderId)->lockForUpdate()->first();
            if (! $delivery || $delivery->assignment_token !== $assignmentToken
                || $delivery->provider_correlation_id !== $correlationId
                || $delivery->booking_phase !== 'claimed'
                || $delivery->booking_requested_at || $delivery->external_delivery_id) {
                return false;
            }
            $delivery->fill([
                'status' => DeliveryStatus::Assigning,
                'assignment_status' => 'in_progress',
                'provider_correlation_id' => null,
                'booking_phase' => null,
                'booking_claimed_at' => null,
            ])->save();
            return true;
        }, 3);
    }

}
