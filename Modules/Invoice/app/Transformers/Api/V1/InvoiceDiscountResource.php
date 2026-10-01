<?php

namespace Modules\Invoice\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoice\Models\InvoiceDiscount;

/** @mixin InvoiceDiscount */
class InvoiceDiscountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            'source' => $this->source->toTrans(),
            'name' => $this->name,
            'currency' => $this->currency,
            'currency_rate' => $this->currency_rate,
            'amount' => $this->amount->withConvertedDefaultCurrency($this->currency_rate),
            'applied_before_tax' => $this->applied_before_tax,
        ];
    }
}
