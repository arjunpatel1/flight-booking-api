<?php

namespace Modules\Invoice\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoice\Models\Invoice;

/** @mixin Invoice */
class ReferenceInvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "uuid" => $this->uuid,
            "invoice_number" => $this->invoice_number,
        ];
    }
}
