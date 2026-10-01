<?php

namespace Modules\Invoice\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoice\Models\InvoiceLine;

/** @mixin InvoiceLine */
class InvoiceLineResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "description" => $this->description,
            "sku" => $this->sku,
            "currency" => $this->currency,
            "currency_rate" => $this->currency_rate,
            "unit_price" => $this->unit_price->withConvertedDefaultCurrency($this->currency_rate),
            "quantity" => $this->quantity,
            "tax_amount" => $this->tax_amount->withConvertedDefaultCurrency($this->currency_rate),
            "line_total_excl_tax" => $this->line_total_excl_tax->withConvertedDefaultCurrency($this->currency_rate),
            "line_total_incl_tax" => $this->line_total_incl_tax->withConvertedDefaultCurrency($this->currency_rate),
        ];
    }
}
