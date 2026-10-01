<?php

namespace Modules\Order\Transformers\Api\V1\Kitchen;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Models\OrderProduct;

/** @mixin OrderProduct */
class KitchenOrderProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "product" => [
                "id" => $this->product_id,
                ...($this->relationLoaded("product")
                    ? [
                        "name" => $this->product->name,
                    ]
                    : [])
            ],
            "status" => $this->status->toTrans(),
            "next_status" => $this->status->nextStatus()?->toTrans(),
            "quantity" => $this->quantity,
            "seat_number" => $this->seat_number,
            "course_number" => $this->course_number,
            "is_held" => $this->isHeld(),
            "created_at_iso" => $this->created_at?->toISOString(),
            "updated_at_iso" => $this->updated_at?->toISOString(),
            "options" => KitchenOrderProductOptionResource::collection($this->whenLoaded('options')),
        ];
    }
}
