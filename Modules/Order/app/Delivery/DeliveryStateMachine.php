<?php

namespace Modules\Order\Delivery;

use Illuminate\Validation\ValidationException;
use Modules\Order\Enums\DeliveryStatus;
use Modules\Order\Events\DeliveryStatusChanged;
use Modules\Order\Models\OrderDelivery;

/** Provider-independent, forward-only delivery state policy. */
final class DeliveryStateMachine
{
    private const ALLOWED = [
        'waiting_for_assignment' => ['fetching_quotes', 'cancelled', 'failed', 'manual_review_required'],
        'fetching_quotes' => ['serviceability_checked', 'quoted', 'failed', 'manual_review_required', 'cancelled'],
        'serviceability_checked' => ['quoted', 'booking_pending', 'failed', 'manual_review_required', 'cancelled'],
        'quoted' => ['booking_pending', 'failed', 'manual_review_required', 'cancelled'],
        'assigning' => ['booking_pending', 'booked', 'rider_searching', 'failed', 'investigation', 'manual_review_required', 'cancelled'],
        'booking_pending' => ['booked', 'rider_searching', 'failed', 'investigation', 'manual_review_required', 'cancel_pending', 'cancelled'],
        'booked' => ['rider_searching', 'rider_assigned', 'cancel_pending', 'cancelled', 'investigation'],
        'rider_searching' => ['rider_assigned', 'cancel_pending', 'cancelled', 'rto', 'failed', 'investigation', 'manual_review_required'],
        'rider_assigned' => ['arrived_at_pickup', 'picked_up', 'in_transit', 'arrived_at_customer', 'delivered', 'cancel_pending', 'cancelled', 'rto', 'failed', 'investigation', 'manual_review_required'],
        'arrived_at_pickup' => ['picked_up', 'in_transit', 'arrived_at_customer', 'delivered', 'cancel_pending', 'cancelled', 'rto', 'failed', 'investigation', 'manual_review_required'],
        'picked_up' => ['in_transit', 'arrived_at_customer', 'delivered', 'rto', 'investigation', 'manual_review_required'],
        'in_transit' => ['arrived_at_customer', 'delivered', 'rto', 'investigation', 'manual_review_required'],
        'arrived_at_customer' => ['delivered', 'rto', 'investigation', 'manual_review_required'],
        'cancel_pending' => ['cancelled', 'investigation', 'manual_review_required'],
        'rto' => ['rto_completed', 'investigation', 'manual_review_required'],
        'rto_completed' => ['returned_after_delivery', 'investigation'],
        'returned_after_delivery' => ['investigation'],
        // Investigation is deliberately non-retryable. A separate, audited
        // reconciliation workflow is required before any future booking.
        'investigation' => [],
        'manual_review_required' => ['cancel_pending', 'cancelled', 'failed', 'investigation'],
        'delivered' => [], 'cancelled' => [], 'failed' => [],
    ];

    public function can(DeliveryStatus $from, DeliveryStatus $to): bool
    {
        return $from === $to || in_array($to->value, self::ALLOWED[$from->value] ?? [], true);
    }

    public function transition(OrderDelivery $delivery, DeliveryStatus $to, array $attributes = []): void
    {
        $from = $delivery->status ?? DeliveryStatus::WaitingForAssignment;
        // Duplicate partner callbacks are idempotent. They must not rewrite
        // rider or tracking details after a milestone, especially delivery.
        if ($from === $to) return;
        if (! $this->can($from, $to)) {
            throw ValidationException::withMessages(['delivery_status' => "Invalid delivery transition: {$from->value} -> {$to->value}."]);
        }
        $delivery->fill([...$attributes, 'status' => $to])->save();
        DeliveryStatusChanged::dispatch((int) $delivery->tenant_id, (int) $delivery->id,
            (int) $delivery->order_id, $from, $to);
    }
}
