<?php

namespace Modules\Order\Transformers\Api\V1\Kitchen;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Models\OrderProductOptionValue;

/** @mixin OrderProductOptionValue */
class KitchenOrderProductOptionValueResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "label" => $this->label,
        ];
    }
}
