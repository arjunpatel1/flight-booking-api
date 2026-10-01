<?php

namespace Modules\Inventory\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\PurchaseReceiptItem;

/** @mixin PurchaseReceiptItem */
class PurchaseReceiptItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "received_quantity" => $this->received_quantity,
            "item" => $this->relationLoaded("item") ? [
                "id" => $this->item->id,
                "ingredient" => $this->item->relationLoaded("ingredient") ? [
                    "id" => $this->item->ingredient->id,
                    "name" => $this->item->ingredient->name,
                    "symbol" => ucfirst(
                        is_array($this->item->ingredient->unit->symbol)
                            ? $this->item->ingredient->unit->symbol[locale()] ?? $this->item->ingredient->unit->symbol[array_key_first($this->item->ingredient->unit->symbol)]
                            : $this->item->ingredient->unit->symbol
                    ),
                ] : null
            ] : null,
        ];
    }
}
