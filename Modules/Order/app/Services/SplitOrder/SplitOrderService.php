<?php

namespace Modules\Order\Services\SplitOrder;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Order\Models\OrderTax;

/**
 * Splits a bill: moves a subset of an order's line items into a new child order so
 * each party can be billed and settled independently (split by items). Totals and
 * GST/taxes are recomputed for both orders via the order's own recalculate() — no
 * hand-rolled tax math — so the parent + child amounts always reconcile to the
 * original.
 */
class SplitOrderService implements SplitOrderServiceInterface
{
    /** @inheritDoc */
    public function split(Order $parent, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));

        if (empty($itemIds)) {
            throw ValidationException::withMessages([
                'items' => __('order::messages.split_requires_items'),
            ]);
        }

        if (in_array($parent->status, [OrderStatus::Cancelled, OrderStatus::Refunded, OrderStatus::Merged], true)) {
            throw ValidationException::withMessages([
                'order_id' => __('order::messages.split_invalid_status'),
            ]);
        }

        if ($parent->payment_status === OrderPaymentStatus::Paid) {
            throw ValidationException::withMessages([
                'order_id' => __('order::messages.split_already_paid'),
            ]);
        }

        return DB::transaction(function () use ($parent, $itemIds) {
            $parent = Order::query()->lockForUpdate()->findOrFail($parent->id);

            $allItemIds = $parent->products()->pluck('id')->map(fn($id) => (int) $id)->all();
            $moving = array_values(array_intersect($itemIds, $allItemIds));

            // Every requested item must belong to this order.
            if (count($moving) !== count($itemIds)) {
                throw ValidationException::withMessages([
                    'items' => __('order::messages.split_items_not_in_order'),
                ]);
            }

            // The parent must keep at least one item — otherwise it's a move, not a split.
            if (count($moving) >= count($allItemIds)) {
                throw ValidationException::withMessages([
                    'items' => __('order::messages.split_keep_one_item'),
                ]);
            }

            $child = $this->createChildOrder($parent);

            // Move the selected line items (and their per-line taxes) to the child.
            OrderProduct::query()->whereIn('id', $moving)->update(['order_id' => $child->id]);
            OrderTax::query()
                ->whereIn('order_product_id', $moving)
                ->update(['order_id' => $child->id]);

            // Recompute both bills from their (new) line items: subtotal, taxes, total, due.
            $parent->recalculate(true);
            $child->recalculate(true);

            return [
                'parent' => $parent->fresh(),
                'child' => $child->fresh(),
            ];
        });
    }

    /**
     * Clone the billable header of the parent into a fresh child order and copy the
     * order-level tax definitions (amounts are recomputed by recalculate()).
     */
    private function createChildOrder(Order $parent): Order
    {
        $child = Order::query()->create([
            'branch_id' => $parent->branch_id,
            'split_from_order_id' => $parent->id,
            'table_id' => $parent->table_id,
            'table_merge_id' => $parent->table_merge_id,
            'pos_register_id' => $parent->pos_register_id,
            'pos_session_id' => $parent->pos_session_id,
            'waiter_id' => $parent->waiter_id,
            'cashier_id' => auth()->id() ?? $parent->cashier_id,
            'customer_id' => $parent->customer_id,
            'gstin' => $parent->gstin,
            'customer_gstin_name' => $parent->customer_gstin_name,
            'status' => $parent->status,
            'type' => $parent->type,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'currency' => $parent->currency,
            'currency_rate' => $parent->currency_rate,
            'subtotal' => 0,
            'total' => 0,
            'due_amount' => 0,
            'guest_count' => 1,
            'order_date' => $parent->order_date,
            'kitchen_display' => $parent->kitchen_display,
        ]);

        // Copy the order-level tax rows (order_product_id null) so the child carries
        // the same tax structure; recalculate() then fills in the correct amounts.
        // replicate() copies raw attributes safely (incl. the translatable name).
        $parent->taxes()->get()->each(function (OrderTax $tax) use ($child) {
            $copy = $tax->replicate(['amount']);
            $copy->order_id = $child->id;
            $copy->order_product_id = null;
            $copy->amount = 0;
            $copy->save();
        });

        return $child;
    }
}
