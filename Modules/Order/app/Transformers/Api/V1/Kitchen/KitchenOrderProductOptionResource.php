<?php

namespace Modules\Order\Transformers\Api\V1\Kitchen;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Models\OrderProductOption;

/** @mixin OrderProductOption */
class KitchenOrderProductOptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "value" => $this->value,
            "values" => KitchenOrderProductOptionValueResource::collection($this->whenLoaded('values')),
        ];
    }
}
