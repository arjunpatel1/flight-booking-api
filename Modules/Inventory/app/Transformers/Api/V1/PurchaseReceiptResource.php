<?php

namespace Modules\Inventory\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\PurchaseReceipt;

/** @mixin PurchaseReceipt */
class PurchaseReceiptResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "reference" => $this->reference,
            "notes" => $this->notes,
            "received_by" => $this->relationLoaded('receivedBy')
                ? [
                    "id" => $this->receivedBy->id,
                    "name" => $this->receivedBy->name,
                ]
                : null,
            "received_at" => dateTimeFormat($this->received_at),
            "items" => $this->relationLoaded("items") ? PurchaseReceiptItemResource::collection($this->items) : []
        ];
    }
}
