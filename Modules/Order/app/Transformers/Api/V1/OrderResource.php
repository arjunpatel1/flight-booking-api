<?php

namespace Modules\Order\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Models\Order;
use Modules\Order\Support\OrderActionPolicy;
use Modules\Order\Support\OrderSourcePresenter;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $source = OrderSourcePresenter::make($this->resource);
        $actionPolicy = OrderActionPolicy::make($this->resource, $request->user());

        return [
            "id" => $this->id,
            "branch" => [
                "id" => $this->branch_id,
                "name" => $this->relationLoaded("branch") ? $this->branch?->name : "",
            ],
            "customer" => OrderCustomerResource::make($this->whenLoaded('customer')),
            'guest_count' => $this->guest_count,
            "table_id" => $this->table_id,
            "table" => $this->relationLoaded("table") ? $this->table?->name : null,
            "floor" => $this->relationLoaded("table") && $this->table?->relationLoaded("floor")
                ? $this->table?->floor?->name
                : null,
            "waiter" => [
                "id" => $this->waiter_id,
                "name" => $this->relationLoaded("waiter") ? $this->waiter?->name : null,
            ],
            "reference_no" => $this->reference_no,
            "order_number" => $this->order_number,
            "type" => $this->type->toTrans(),
            "source_type" => $source['source_type'],
            "source_label" => $source['source_label'],
            "source_color" => $source['source_color'],
            "aggregator_provider" => $source['aggregator_provider'],
            "status" => $this->status->toTrans(),
            "payment_status" => $this->payment_status->toTrans(),
            "action_policy" => $actionPolicy,
            "allow_refund" => $actionPolicy['refund']['allowed'],
            "allow_cancel" => $actionPolicy['cancel']['allowed'],
            "allow_edit" => $actionPolicy['edit']['allowed'],
            "allow_print" => $actionPolicy['print']['allowed'],
            "allow_receive_payment" => $actionPolicy['receive_payment']['allowed'],
            "allow_update_status" => $actionPolicy['update_status']['allowed'],
            "products_count" => $this->products_count
                ?? ($this->relationLoaded('products') ? $this->products->count() : null),
            "total" => $this->total->withConvertedDefaultCurrency($this->currency_rate),
            // Keep a flat display value for data-table clients. Some table
            // renderers do not resolve nested JsonResource/Money objects.
            "total_formatted" => $this->total
                ->convertToDefault($this->currency_rate)
                ->format(),
            "due_amount" => $this->due_amount->withConvertedDefaultCurrency($this->currency_rate),
            "additional_payments" => collect(data_get($this->fulfilmentDetails(), 'additional_payments', []))->map(fn ($amount, $label) => [
                'label' => str($label)->replace(['_', '-'], ' ')->title()->toString(),
                'amount' => (new \Modules\Support\Money((float) $amount, $this->currency))->withConvertedDefaultCurrency($this->currency_rate),
            ])->values(),
            "refunded_amount" => $this->getRefundedAmount(),
            "created_at" => dateTimeFormat($this->created_at),
            // Machine-readable timestamp for clients that compute relative time.
            "created_at_iso" => $this->created_at?->toIso8601String(),
        ];
    }
}
