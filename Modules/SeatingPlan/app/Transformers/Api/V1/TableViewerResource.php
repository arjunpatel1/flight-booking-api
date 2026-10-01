<?php

namespace Modules\SeatingPlan\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Order\Support\OrderActionPolicy;
use Modules\Order\Support\KitchenSla;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Support\TableActionPolicy;

/** @mixin Table */
class TableViewerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $activeOrders = $this->relationLoaded("activeOrders") ? $this->activeOrders : collect();
        $activeGuestCount = (int) ($this->relationLoaded("activeOrders")
            ? $activeOrders->sum('guest_count')
            : $this->active_orders_guest_count);
        $viewerStatus = $this->resolveViewerStatus($activeOrders->count(), $activeGuestCount);
        $statusPriority = [
            OrderStatus::Ready->value => 1,
            OrderStatus::Preparing->value => 2,
            OrderStatus::Confirmed->value => 3,
            OrderStatus::Pending->value => 4,
            OrderStatus::Served->value => 5,
        ];
        $currentOrder = $activeOrders
            ->sortBy(fn(Order $order) => $statusPriority[$order->status->value] ?? 99)
            ->first();
        $productStatusCounts = $activeOrders
            ->flatMap(fn(Order $order) => $order->relationLoaded('products') ? $order->products : collect())
            ->groupBy(function ($product) {
                $status = $product->status;

                return $status instanceof OrderProductStatus ? $status->value : (string) $status;
            })
            ->map(fn($products) => $products->sum('quantity'));

        return [
            "id" => $this->id,
            "name" => $this->name,
            "guest_count" => $activeGuestCount,
            "capacity" => $this->capacity,
            "shape" => $this->shape->toTrans(),
            "pos_x" => $this->pos_x,
            "pos_y" => $this->pos_y,
            "rotation" => $this->rotation,
            "scale" => $this->scale,
            "branch" => [
                "id" => $this->branch_id,
                "name" => $this->relationLoaded("branch") ? $this->branch?->name : "",
            ],
            "floor" => [
                "id" => $this->floor_id,
                "name" => $this->relationLoaded("floor") ? $this->floor?->name : "",
                "layout_width" => $this->relationLoaded("floor") ? $this->floor?->layout_width : null,
                "layout_height" => $this->relationLoaded("floor") ? $this->floor?->layout_height : null,
                "show_grid" => $this->relationLoaded("floor") ? $this->floor?->show_grid : null,
                "show_guide_lines" => $this->relationLoaded("floor") ? $this->floor?->show_guide_lines : null,
                "show_zone_labels" => $this->relationLoaded("floor") ? $this->floor?->show_zone_labels : null,
                "show_table_labels" => $this->relationLoaded("floor") ? $this->floor?->show_table_labels : null,
                "compact_tables" => $this->relationLoaded("floor") ? $this->floor?->compact_tables : null,
                "zone_layouts" => $this->relationLoaded("floor") ? ($this->floor?->zone_layouts ?: []) : [],
                "layout_elements" => $this->relationLoaded("floor") ? ($this->floor?->layout_elements ?: []) : [],
            ],
            "zone" => [
                "id" => $this->zone_id,
                "name" => $this->relationLoaded("zone") ? $this->zone?->name : "",
            ],
            "has_merged" => !is_null($this->current_merge_id),
            "action_policy" => TableActionPolicy::make($this->resource, $request->user(), $activeOrders->count()),
            "waiter_name" => $currentOrder && $currentOrder->relationLoaded('waiter')
                ? $currentOrder->waiter?->name
                : null,
            "status" => $viewerStatus->toTrans(),
            "active_orders_summary" => [
                "count" => $activeOrders->count(),
                "current_status" => $currentOrder?->status->toTrans(),
                "latest_reference" => $currentOrder?->order_number ?? $currentOrder?->reference_no,
                "created_at" => $currentOrder?->created_at?->toISOString(),
                "updated_at" => $currentOrder?->updated_at?->toISOString(),
                "sla_minutes" => app(KitchenSla::class)->thresholdMinutes(),
                "product_status_counts" => [
                    "pending" => (int) ($productStatusCounts[OrderProductStatus::Pending->value] ?? 0),
                    "preparing" => (int) ($productStatusCounts[OrderProductStatus::Preparing->value] ?? 0),
                    "ready" => (int) ($productStatusCounts[OrderProductStatus::Ready->value] ?? 0),
                    "served" => (int) ($productStatusCounts[OrderProductStatus::Served->value] ?? 0),
                ],
                "orders" => $activeOrders
                    ->take(3)
                    ->map(function (Order $order) use ($request) {
                        $actionPolicy = OrderActionPolicy::make($order, $request->user());

                        return [
                            "id" => $order->id,
                            "reference_no" => $order->reference_no,
                            "order_number" => $order->order_number,
                            "status" => $order->status->toTrans(),
                            "next_status" => $order->next_status?->toTrans(),
                            "payment_status" => [
                                "id" => $order->payment_status->value,
                                "name" => $order->payment_status->trans(),
                            ],
                            "allow_update_status" => $actionPolicy['update_status']['allowed'],
                            "allow_receive_payment" => $actionPolicy['receive_payment']['allowed'],
                            "allow_edit" => $actionPolicy['edit']['allowed'],
                            "allow_cancel" => $actionPolicy['cancel']['allowed'],
                            "allow_refund" => $actionPolicy['refund']['allowed'],
                            "allow_print" => $actionPolicy['print']['allowed'],
                            "action_policy" => $actionPolicy,
                            "created_at" => $order->created_at?->toISOString(),
                            "total" => $order->total->toArray(),
                            "items_count" => $order->relationLoaded('products')
                                ? (int) $order->products->sum('quantity')
                                : null,
                            "waiter_name" => $order->relationLoaded('waiter')
                                ? $order->waiter?->name
                                : null,
                        ];
                    })
                    ->values(),
            ],
        ];
    }

    private function resolveViewerStatus(int $activeOrderCount, int $activeGuestCount): TableStatus
    {
        $status = $this->status;

        if ($status === TableStatus::Merged) {
            return $status;
        }

        if ($activeOrderCount > 0 || $activeGuestCount > 0) {
            return TableStatus::Occupied;
        }

        if ($status === TableStatus::Occupied) {
            return TableStatus::Available;
        }

        return $status;
    }
}
