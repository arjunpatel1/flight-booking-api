<?php

namespace Modules\Invoice\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoice\Models\InvoiceTax;

/** @mixin InvoiceTax */
class InvoiceTaxResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "code" => $this->code,
            "rate" => $this->rate,
            "currency" => $this->currency,
            "currency_rate" => $this->currency_rate,
            "type" => $this->type->toTrans(),
            'amount' => $this->amount->withConvertedDefaultCurrency($this->currency_rate),
            'compound' => $this->compound,
        ];
    }
}
