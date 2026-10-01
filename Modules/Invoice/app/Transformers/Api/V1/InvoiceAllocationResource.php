<?php

namespace Modules\Invoice\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payment\Models\PaymentAllocation;

/** @mixin PaymentAllocation */
class InvoiceAllocationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "currency" => $this->currency,
            "currency_rate" => $this->currency_rate,
            'amount' => $this->amount->withConvertedDefaultCurrency($this->currency_rate),
            'payment' => InvoiceAllocationPaymentResource::make($this->whenLoaded('payment')),
        ];
    }
}
