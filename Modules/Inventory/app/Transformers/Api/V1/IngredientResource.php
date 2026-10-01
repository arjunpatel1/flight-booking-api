<?php

namespace Modules\Inventory\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\Ingredient;

/** @mixin Ingredient */
class IngredientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "branch" => [
                "id" => $this->branch_id,
                "name" => $this->relationLoaded("branch") ? $this->branch?->name : "",
            ],
            "unit_id" => $this->unit_id,
            "unit_name" => $this->relationLoaded("unit") ? $this->unit->name : "-",
            "cost_per_unit" => $this->cost_per_unit,
            "alert_quantity" => $this->alert_quantity,
            "current_stock" => $this->current_stock,
            "is_returnable" => $this->is_returnable,
            "updated_at" => dateTimeFormat($this->updated_at),
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
