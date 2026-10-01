<?php

namespace Modules\Inventory\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Log;
use Modules\Inventory\Models\PurchaseItem;

/** @mixin PurchaseItem */
class PurchaseItemResource extends JsonResource
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
                "name" => $this->relationLoaded("ingredient") ? $this->ingredient->name : null,
                "unit" => $this->relationLoaded("ingredient") && $this->ingredient->relationLoaded("unit")
                    ? [
                        "name" => $this->ingredient->unit->name,
                        "symbol" => is_array($this->ingredient->unit->symbol)
                            ? $this->ingredient->unit->symbol
                            : ucfirst($this->ingredient->unit->symbol),
                    ]
                    : null,
            ],
            "quantity" => $this->quantity,
            "received_quantity" => $this->received_quantity,
            "unit_cost" => $this->unit_cost->withConvertedDefaultCurrency($this->currency_rate),
            "line_total" => $this->line_total->withConvertedDefaultCurrency($this->currency_rate),
        ];
    }
}
