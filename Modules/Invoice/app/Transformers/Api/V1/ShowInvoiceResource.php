<?php

namespace Modules\Invoice\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoice\Models\Invoice;
use Modules\Support\Enums\DateTimeFormat;

/** @mixin Invoice */
class ShowInvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "invoice_number" => $this->invoice_number,
            "reference_invoice" => ReferenceInvoiceResource::make($this->whenLoaded('referenceInvoice')),
            "uuid" => $this->uuid,
            "branch" => [
                "id" => $this->branch_id,
                "name" => $this->relationLoaded("branch") ? $this->branch?->name : "",
            ],
            "seller" => InvoicePartyResource::make($this->whenLoaded("seller")),
            "buyer" => InvoicePartyResource::make($this->whenLoaded("buyer")),
            "type" => $this->type->toTrans(),
            "purpose" => $this->purpose->toTrans(),
            "invoice_kind" => $this->invoice_kind->toTrans(),
            "currency" => $this->currency,
            "currency_rate" => $this->currency_rate,
            "subtotal" => $this->subtotal->withConvertedDefaultCurrency($this->currency_rate),
            "tax_total" => $this->tax_total->withConvertedDefaultCurrency($this->currency_rate),
            "discount_total" => $this->discount_total->withConvertedDefaultCurrency($this->currency_rate),
            "total" => $this->total->withConvertedDefaultCurrency($this->currency_rate),
            "rounding_adjustment" => $this->rounding_adjustment->amount(),
            "paid_amount" => $this->paid_amount->withConvertedDefaultCurrency($this->currency_rate),
            "refunded_amount" => $this->refunded_amount->withConvertedDefaultCurrency($this->currency_rate),
            "net_paid" => $this->net_paid->withConvertedDefaultCurrency($this->currency_rate),
            "status" => $this->status->toTrans(),
            "settlement_status" => InvoiceResource::make($this->resource)->resolve($request)['settlement_status'],
            "issued_at" => [
                "full_date" => dateTimeFormat($this->issued_at),
                "date" => dateTimeFormat($this->issued_at, DateTimeFormat::Date),
                "time" => dateTimeFormat($this->issued_at, DateTimeFormat::Time),
            ],
            "discounts" => InvoiceDiscountResource::collection($this->whenLoaded('discounts')),
            "taxes" => InvoiceTaxResource::collection($this->whenLoaded('taxes')),
            "allocations" => InvoiceAllocationResource::collection($this->whenLoaded('allocations')),
            "lines" => InvoiceLineResource::collection($this->whenLoaded('lines')),
            "qrcode" => $this->getQrcodeBase64(),
            "qrcode_mime_type" => $this->getQrcodeMimeType(),
            "download_url" => $this->getDownloadUrl(),
            "pdf_url" => $this->getPDFUrl(),
        ];
    }
}
