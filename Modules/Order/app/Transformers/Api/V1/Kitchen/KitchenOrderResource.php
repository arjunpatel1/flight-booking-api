<?php

namespace Modules\Order\Transformers\Api\V1\Kitchen;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Support\KitchenSla;
use Modules\Support\Enums\DateTimeFormat;

/** @mixin Order */
class KitchenOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $sla = $this->slaPayload();

        return [
            "id" => $this->id,
            "reference_no" => $this->reference_no,
            "order_number" => $this->order_number,
            "status" => $this->status->toTrans(),
            "next_status" => $this->next_status->toTrans(),
            "type" => $this->type->toTrans(),
            "payment_status" => $this->payment_status->toTrans(),
            "table" => $this->type === OrderType::DineIn
                ? [
                    "id" => $this->table_id,
                    "name" => $this->relationLoaded("table") ? $this->table?->name : null,
                ]
                : null,
            "products" => KitchenOrderProductResource::collection($this->whenLoaded("products")),
            "items_count" => $this->relationLoaded("products") ? $this->products->sum('quantity') : null,
            "next_held_course" => $this->relationLoaded("products")
                ? $this->products
                    ->filter(fn ($p) => $p->isHeld())
                    ->min('course_number')
                : null,
            "has_held_courses" => $this->relationLoaded("products")
                ? $this->products->contains(fn ($p) => $p->isHeld())
                : false,
            "time" => dateTimeFormat($this->created_at, DateTimeFormat::Time),
            "date" => dateTimeFormat($this->created_at, DateTimeFormat::Date),
            "created_at" => dateTimeFormat($this->created_at, DateTimeFormat::DateTime),
            "created_at_iso" => $this->created_at?->toISOString(),
            "updated_at_iso" => $this->updated_at?->toISOString(),
            "sla" => $sla,
            "sla_due_at" => $sla['due_at'],
            "elapsed_minutes" => $sla['elapsed_minutes'],
            "delay_minutes" => $sla['delay_minutes'],
            "is_delayed" => $sla['is_delayed'],
        ];
    }

    private function slaPayload(): array
    {
        return app(KitchenSla::class)->payload($this->resource);
    }
}
