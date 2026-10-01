<?php

namespace Modules\Inventory\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Enums\PurchaseStatus;
use Modules\Inventory\Models\Purchase;
use Modules\Support\Enums\DateTimeFormat;

/** @mixin Purchase */
class PurchaseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $data = [
            "id" => $this->id,
            "reference_number" => $this->reference_number,
            "branch" => [
                "id" => $this->branch_id,
                "name" => $this->relationLoaded("branch") ? $this->branch?->name : null,
            ],
            "supplier" => [
                "id" => $this->supplier_id,
                "name" => $this->relationLoaded("supplier") ? $this->supplier?->name : null,
            ],
            "total" => $this->total->withConvertedDefaultCurrency($this->currency_rate),
            "status" => $this->status?->toTrans(),
            "allow_edit" => !in_array($this->status, [PurchaseStatus::Received, PurchaseStatus::Cancelled], true),
            "expected_at" => dateTimeFormat($this->expected_at, DateTimeFormat::Date),
            "updated_at" => dateTimeFormat($this->updated_at),
            "created_at" => dateTimeFormat($this->created_at),
        ];

        if ($request->routeIs("admin.purchases.show")) {
            $data = [
                ...$data,
                "discount" => $this->discount->withConvertedDefaultCurrency($this->currency_rate),
                "tax" => $this->tax->withConvertedDefaultCurrency($this->currency_rate),
                "sub_total" => $this->sub_total->withConvertedDefaultCurrency($this->currency_rate),
                "notes" => $this->notes,
                "items" => $this->relationLoaded("items") ? PurchaseItemResource::collection($this->items) : [],
            ];
        }

        if ($this->relationLoaded("purchaseReceipts")) {
            $data = [
                ...$data,
                "receipts" => PurchaseReceiptResource::collection($this->purchaseReceipts)
            ];
        }


        return $data;
    }
}
