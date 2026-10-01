<?php

namespace Modules\Order\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Models\Order;
use Modules\Order\Delivery\DeliveryReadModel;

/** @mixin Order */
class ShowOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new OrderResource($this->resource))->resolve(),
            "table" => [
                "id" => $this->table_id,
                "name" => $this->relationLoaded("table") ? $this->table?->name : "",
            ],
            "waiter" => [
                "id" => $this->waiter_id,
                "name" => $this->relationLoaded("waiter") ? $this->waiter?->name : "",
            ],
            "cashier" => [
                "id" => $this->cashier_id,
                "name" => $this->relationLoaded("cashier") ? $this->cashier?->name : "",
            ],
            "created_by" => [
                "id" => $this->created_by,
                "name" => $this->relationLoaded("createdBy") ? $this->createdBy?->name : "",
            ],
            "pos_register" => [
                "id" => $this->pos_register_id,
                "name" => $this->relationLoaded("posRegister") ? $this->posRegister?->name : "",
            ],
            "customer" => OrderCustomerResource::make($this->whenLoaded('customer')),
            "pos_session_id" => $this->pos_session_id,
            "currency" => $this->currency,
            "currency_rate" => $this->currency_rate,
            "subtotal" => $this->subtotal->withConvertedDefaultCurrency($this->currency_rate),
            ...(auth()->user()->can('admin.orders.financials')
                ? [
                    "cost_price" => $this->cost_price->withConvertedDefaultCurrency($this->currency_rate),
                    "revenue" => $this->revenue->withConvertedDefaultCurrency($this->currency_rate),
                ] : []),
            "guest_count" => $this->guest_count,
            "notes" => $this->notes,
            "fulfilment" => $this->resource->fulfilmentDetails(),
            "delivery" => DeliveryReadModel::admin($this->relationLoaded('delivery') ? $this->delivery : null, (bool) $request->user()?->can('admin.orders.financials')),
            "products" => OrderProductResource::collection($this->whenLoaded('products')),
            "taxes" => OrderTaxResource::collection($this->whenLoaded('taxes')),
            "discount" => $this->hasDiscount() ? new OrderDiscountResource($this->whenLoaded('discount')) : null,
            "payments" => OrderPaymentResource::collection($this->whenLoaded('payments')),
            "invoices" => OrderInvoiceResource::collection($this->all_invoices),
            "status_logs" => OrderStatusLogResource::collection($this->whenLoaded('statusLogs')),
            "merged_into_order" => $this->relationLoaded('mergedIntoOrder') && !is_null($this->mergedIntoOrder)
                ? [
                    "id" => $this->mergedIntoOrder->id,
                    "order_number" => $this->mergedIntoOrder->order_number,
                    "reference_no" => $this->mergedIntoOrder->reference_no,
                ]
                : null,
            "merged_by" => $this->relationLoaded('mergedBy') && !is_null($this->mergedBy)
                ? [
                    "id" => $this->mergedBy->id,
                    "name" => $this->mergedBy->name,
                ]
                : null,
            "details" => [
                "car_plate" => $this->car_plate,
                "car_description" => $this->car_description,
                "scheduled_at" => dateTimeFormat($this->scheduled_at),
            ],
            "merged_at" => dateTimeFormat($this->merged_at),
            "served_at" => dateTimeFormat($this->served_at),
            "closed_at" => dateTimeFormat($this->closed_at),
            "updated_at" => dateTimeFormat($this->updated_at)
        ];
    }
}
