<?php

namespace Modules\Pos\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pos\Models\PosRegister;

/** @mixin PosRegister */
class PosRegisterResource extends JsonResource
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
            "invoice_printer" => [
                "id" => $this->invoice_printer_id,
                "name" => $this->relationLoaded("invoicePrinter") ? $this->invoicePrinter?->name : "",
            ],
            "bill_printer" => [
                "id" => $this->bill_printer_id,
                "name" => $this->relationLoaded("billPrinter") ? $this->billPrinter?->name : "",
            ],
            "delivery_printer" => [
                "id" => $this->delivery_printer_id,
                "name" => $this->relationLoaded("deliveryPrinter") ? $this->deliveryPrinter?->name : "",
            ],
            "waiter_printer" => [
                "id" => $this->waiter_printer_id,
                "name" => $this->relationLoaded("waiterPrinter") ? $this->waiterPrinter?->name : "",
            ],
            "name" => $this->name,
            "code" => $this->code,
            "note" => $this->note,
            "is_active" => $this->is_active,
            "updated_at" => dateTimeFormat($this->updated_at),
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
