<?php

namespace Modules\SeatingPlan\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Models\Order;
use Modules\Order\Transformers\Api\V1\ActiveOrderResource;
use Modules\SeatingPlan\Enums\TableMergeType;
use Modules\SeatingPlan\Models\Table;

/** @mixin Table */
class TableShowViewerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $orders = null;
        $currentMerge = !is_null($this->current_merge_id)
        && $this->relationLoaded("currentMerge")
        && !is_null($this->currentMerge)
            ? [
                "id" => $this->current_merge_id,
                "type" => $this->currentMerge->type->toTrans(),
                "primary_table_id" => $this->currentMerge->table_id,
                "is_primary" => $this->currentMerge->table_id == $this->id,
                "members" => $this->currentMerge->relationLoaded("members")
                    ? $this->currentMerge
                        ->members
                        ->map(fn($member) => [
                            "id" => $member->id,
                            "table" => [
                                "id" => $member->table_id,
                                "name" => $member->relationLoaded("table") ? $member->table->name : "",
                            ],
                            "is_main" => $member->is_main,
                        ])
                    : [],
            ] : null;

        $with = [
            "products" => fn($query) => $query
                ->whereNotIn("status", [OrderProductStatus::Cancelled, OrderProductStatus::Refunded])
                ->without("taxes", "options"),
            "customer:id,name",
            "table:id,name",
            "waiter:id,name",
        ];
        if (!is_null($currentMerge)) {
            switch ($this->currentMerge->type) {
                case TableMergeType::Order:
                case TableMergeType::Capacity:
                    $orders = Order::query()
                        ->where('table_id', $this->currentMerge->table_id)
                        ->activeOrders()
                        ->with($with)
                        ->get();
                    break;
                case TableMergeType::Billing:
                    $orders = Order::query()
                        ->where('table_merge_id', $this->current_merge_id)
                        ->with($with)
                        ->activeOrders()
                        ->get();
                    if ($orders->count() == 0) {
                        $orders = null;
                    }
                    break;
            }
        } else {
             $orders = Order::query()
                ->where('table_id', $this->id)
                ->with($with)
                ->activeOrders()
                ->get();
        }

        // Set the fresh orders as the activeOrders relation so TableViewerResource
        // computes the correct status, count, and product_status_counts.
        if (!is_null($orders)) {
            $this->setRelation('activeOrders', $orders);
        }

        $hasActiveOrders = ! is_null($orders)
            && (! method_exists($orders, 'isEmpty') || ! $orders->isEmpty());

        $data = [
            ...((new TableViewerResource($this->resource))->resolve()),
            "uuid" => $this->uuid,
            "capacity" => $this->capacity,
            "waiter" => [
                "id" => $this->assigned_waiter_id,
                "name" => $this->relationLoaded("waiter") ? $this->waiter?->name : "",
            ],
            "current_merge" => $currentMerge,
            "allow_split" => ! is_null($currentMerge) && ! $hasActiveOrders,
            "shape" => $this->shape->toTrans(),
        ];
        $data['orders'] = ActiveOrderResource::collection($orders);
        return $data;
    }
}
