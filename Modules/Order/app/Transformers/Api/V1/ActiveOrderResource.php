<?php

namespace Modules\Order\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Support\OrderActionPolicy;
use Modules\Order\Support\OrderSourcePresenter;
use Modules\Support\Enums\DateTimeFormat;

/** @mixin Order */
class ActiveOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $actionPolicy = OrderActionPolicy::make($this->resource, $request->user());
        $source = OrderSourcePresenter::make($this->resource);

        return [
            "id" => $this->id,
            "reference_no" => $this->reference_no,
            "order_number" => $this->order_number,
            "customer_name" => $this->getCustomerName(),
            "type" => $this->type->toTrans(),
            "source_type" => $source['source_type'],
            "source_label" => $source['source_label'],
            "source_color" => $source['source_color'],
            "previous_status" => $this->previous_status?->toTrans(),
            "status" => $this->status->toTrans(),
            "next_status" => $this->next_status?->toTrans(),
            "payment_status" => $this->payment_status->toTrans(),
            "total" => $this->total,
            'guest_count' => $this->guest_count,
            "due_amount" => $this->due_amount,
            "action_policy" => $actionPolicy,
            "allow_refund" => $actionPolicy['refund']['allowed'],
            "allow_cancel" => $actionPolicy['cancel']['allowed'],
            "allow_update_status" => $actionPolicy['update_status']['allowed'],
            "allow_receive_payment" => $actionPolicy['receive_payment']['allowed'],
            "allow_edit" => $actionPolicy['edit']['allowed'],
            "scheduled_at" => dateTimeFormat($this->scheduled_at),
            "table" => $this->type === OrderType::DineIn
                ? [
                    "id" => $this->table_id,
                    "name" => $this->relationLoaded("table") ? $this->table?->name : null,
                ]
                : null,
            "waiter" => [
                "id" => $this->waiter_id,
                "name" => $this->relationLoaded("waiter") ? $this->waiter?->name : null,
            ],
            "products" => [
                "count" => $this->products->count(),
                "names" => implode(",", $this->products->pluck('name')->toArray()),
            ],
            "time" => dateTimeFormat($this->created_at, DateTimeFormat::Time),
            "date" => dateTimeFormat($this->created_at, DateTimeFormat::Date),
            // Full timestamp so the client can render an accurate "time ago"
            // instead of defaulting to now() when only time/date are present.
            "created_at_iso" => $this->created_at?->toIso8601String(),
        ];
    }
}
