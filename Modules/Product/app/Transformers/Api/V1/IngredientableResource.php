<?php

namespace Modules\Product\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Product\Models\Ingredientable;

/** @mixin Ingredientable */
class IngredientableResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "ingredient" => [
                "id" => $this->ingredient_id,
                "name" => $this->relationLoaded("ingredient") ? $this->ingredient?->name : "",
            ],
            "quantity" => $this->quantity,
            "operation" => $this->operation->toTrans(),
            "loss_pct" => $this->loss_pct,
            "note" => $this->note,
        ];
    }
}
