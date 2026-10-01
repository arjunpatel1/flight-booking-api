<?php

namespace Modules\Order\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoice\Models\Invoice;

/** @mixin Invoice */
class OrderInvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "invoice_number" => $this->invoice_number,
            "invoice_kind" => $this->invoice_kind->toTrans(),
            "total" => $this->total->withConvertedDefaultCurrency($this->currency_rate),
            "issued_at" => dateTimeFormat($this->issued_at),
            "download_url" => $this->getDownloadUrl(),
            "pdf_url" => $this->getPDFUrl(),
        ];
    }
}
