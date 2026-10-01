<?php

namespace Modules\Order\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Models\OrderStatusLog;

/**
 * Status transitions, guards and status logging for the Order model.
 */
trait HasOrderStatusFlow
{
    public function cancelIsAllowed(): bool
    {
        return ! $this->deliveryIsProviderManaged()
            && $this->payment_status->isUnpaid()
            && !in_array($this->status, [OrderStatus::Cancelled, OrderStatus::Merged, OrderStatus::Refunded]);
    }

    public function refundIsAllowed(): bool
    {
        return ! $this->deliveryIsProviderManaged()
            && !$this->payment_status->isUnpaid()
            && !in_array($this->status, [OrderStatus::Cancelled, OrderStatus::Refunded, OrderStatus::Merged])
            && ($this->status == OrderStatus::Pending || $this->created_at->diffInHours(now()) < 24);
    }

    public function allowUpdateStatus(): bool
    {
        // Food readiness is a restaurant action. Once logistics is owned by
        // an external partner, only its authenticated milestone flow may move
        // the order into transit or complete it.
        if ($this->type === OrderType::Delivery
            && in_array($this->next_status, [OrderStatus::OutForDelivery, OrderStatus::Completed], true)
            && $this->deliveryIsProviderManaged()) {
            return false;
        }
        if (! (bool) setting('waiter_table_status_flow_enabled', true)) {
            return false;
        }

        return !(is_null($this->next_status)
            || ($this->next_status != OrderStatus::Confirmed && !$this->isScheduledForToday())
            || (!$this->payment_status->isPaid() && $this->next_status == OrderStatus::Completed));
    }

    public function deliveryIsProviderManaged(): bool
    {
        if ($this->type !== OrderType::Delivery) return false;
        $delivery = $this->delivery;

        if (! $delivery || $delivery->status === \Modules\Order\Enums\DeliveryStatus::Cancelled) return false;

        return $delivery->mode === 'partner_api'
            || ($delivery->mode === 'third_party' && filled($delivery->external_delivery_id));
    }

    /**
     * Check if the order is scheduled for today (ignores time of day).
     */
    public function isScheduledForToday(): bool
    {
        if ($this->scheduled_at) {
            return $this->scheduled_at->toDateString() <= now()->toDateString();
        }

        return true;
    }

    public function storeStatusLog(
        OrderStatus $status,
        ?int        $changedById = null,
        ?int        $reasonId = null,
        ?string     $note = null,
    ): OrderStatusLog
    {
        return $this->statusLogs()
            ->create([
                "changed_by" => $changedById,
                "reason_id" => $reasonId,
                "status" => $status,
                "note" => $note
            ]);
    }

    public function nextStatus(): Attribute
    {
        return Attribute::get(
            fn() => match ($this->status) {
                OrderStatus::Pending => OrderStatus::Confirmed,
                OrderStatus::Confirmed => OrderStatus::Preparing,
                OrderStatus::Preparing => OrderStatus::Ready,
                OrderStatus::Ready => match ($this->type) {
                    OrderType::DineIn => OrderStatus::Served,
                    OrderType::Delivery => OrderStatus::OutForDelivery,
                    default => OrderStatus::Completed,
                },
                OrderStatus::OutForDelivery => OrderStatus::Completed,
                OrderStatus::Served => OrderStatus::Completed,
                default => null
            }
        );
    }

    public function previousStatus(): Attribute
    {
        return Attribute::get(
            fn() => match ($this->status) {
                OrderStatus::Confirmed => OrderStatus::Pending,
                OrderStatus::Preparing => OrderStatus::Confirmed,
                OrderStatus::Ready => OrderStatus::Preparing,
                OrderStatus::Served => OrderStatus::Ready,
                OrderStatus::OutForDelivery => OrderStatus::Ready,
                OrderStatus::Completed => match ($this->type) {
                    OrderType::DineIn => OrderStatus::Served,
                    OrderType::Delivery => OrderStatus::OutForDelivery,
                    default => OrderStatus::Ready,
                },
                default => null
            }
        );
    }

    public function editIsAllowed(): bool
    {
        return !$this->payment_status->isPaid()
            && in_array(
                $this->status,
                [
                    OrderStatus::Pending,
                    OrderStatus::Confirmed,
                    OrderStatus::Preparing,
                    OrderStatus::Ready,
                    OrderStatus::Served,
                ]
            );
    }

    /**
     * Roll an already-progressed order back to Preparing when it still has
     * Pending products (e.g. after items were added/updated).
     */
    public function revertOrderStatusToPreparingIfModified(): void
    {
        if (
            !in_array($this->status, [OrderStatus::Pending, OrderStatus::Confirmed])
            && $this->products()->where('status', OrderProductStatus::Pending)->exists()
        ) {
            $this->update([
                'status' => OrderStatus::Preparing,
            ]);

            event(new OrderUpdateStatus(
                order: $this,
                status: OrderStatus::Preparing,
                note: "Order was modified. Status changed back to Preparing.",
            ));
        }
    }

    public function recalculateOrderStatus(): void
    {
        $statuses = $this->products()
            ->whereNotIn("status", [
                OrderProductStatus::Cancelled,
                OrderProductStatus::Refunded,
            ])
            ->pluck('status')
            ->unique();

        $newStatus = match (true) {
            $statuses->contains(OrderProductStatus::Pending)
            || $statuses->contains(OrderProductStatus::Preparing)
            => OrderStatus::Preparing,
            $statuses->every(fn($s) => $s === OrderProductStatus::Served) => OrderStatus::Served,
            $statuses->contains(OrderProductStatus::Ready) => OrderStatus::Ready,
            default => OrderStatus::Confirmed,
        };

        if ($this->status !== $newStatus) {
            $this->update(['status' => $newStatus]);

            event(new OrderUpdateStatus(
                order: $this,
                status: $newStatus,
                changedById: auth()->id(),
                stopUpdateOrderProductStatus: true
            ));
        }
    }
}
