<?php

namespace Modules\Option\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Option\Models\OptionValue;
use Modules\Product\Transformers\Api\V1\IngredientableResource;

/** @mixin OptionValue */
class OptionValueResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "label" => $this->label,
            "price_type" => $this->price_type,
            "price" => $this->price,
            "ingredients" => $this->relationLoaded("ingredients") ? IngredientableResource::collection($this->ingredients) : [],
        ];
    }
}
