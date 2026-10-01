<?php

namespace Modules\Invoice\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payment\Models\Payment;

/** @mixin Payment */
class InvoiceAllocationPaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "method" => $this->method->toTrans(),
            "type" => $this->type->toTrans(),
            "transaction_id" => $this->transaction_id,
        ];
    }
}
