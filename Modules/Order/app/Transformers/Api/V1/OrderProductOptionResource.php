<?php

namespace Modules\Order\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Models\OrderProductOption;

/** @mixin OrderProductOption */
class OrderProductOptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "option_id" => $this->option_id,
            "name" => $this->name,
            "value" => $this->value,
            "values" => OrderProductOptionValueResource::collection($this->whenLoaded('values')),
        ];
    }
}
