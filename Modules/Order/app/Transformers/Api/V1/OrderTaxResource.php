<?php

namespace Modules\Order\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Models\OrderTax;

/** @mixin OrderTax */
class OrderTaxResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "tax_id" => $this->tax_id,
            "name" => $this->name,
            "rate" => $this->rate,
            "type" => $this->type,
            "amount" => $this->amount->withConvertedDefaultCurrency($this->currency_rate),
            "compound" => $this->compound
        ];
    }
}
