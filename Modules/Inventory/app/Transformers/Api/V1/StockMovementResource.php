<?php

namespace Modules\Inventory\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\StockMovement;

/** @mixin StockMovement */
class StockMovementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "branch" => [
                "id" => $this->branch_id,
                "name" => $this->relationLoaded("branch") ? $this->branch?->name : "",
            ],
            "ingredient" => [
                "id" => $this->ingredient_id,
                "name" => $this->relationLoaded("ingredient") ? $this->ingredient->name : "",
                "symbol" => $this->relationLoaded("ingredient") ? $this->ingredient->name : "",
            ],
            "type" => $this->type->toTrans(),
            "quantity" => $this->quantity . (
                $this->relationLoaded("ingredient") && $this->ingredient->relationLoaded("unit")
                    ? " " . (is_array($this->ingredient->unit->symbol)
                        ? ucfirst($this->ingredient->unit->getTranslation('symbol', locale()))
                        : ucfirst($this->ingredient->unit->symbol))
                    : ''),
            "note" => $this->note,
            "source" => !is_null($this->source_id)
                ? [
                    "id" => $this->source_id,
                    "type" => $this->source_type,
                    "name" => $this->getSourceName(),
                ] : null,
            "updated_at" => dateTimeFormat($this->updated_at),
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
