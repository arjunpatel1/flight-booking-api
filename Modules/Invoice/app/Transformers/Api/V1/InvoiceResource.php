<?php

namespace Modules\Invoice\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoice\Models\Invoice;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "invoice_number" => $this->invoice_number,
            "uuid" => $this->uuid,
            "reference_invoice" => ReferenceInvoiceResource::make($this->whenLoaded('referenceInvoice')),
            "branch" => [
                "id" => $this->branch_id,
                "name" => $this->relationLoaded("branch") ? $this->branch?->name : "",
            ],
            "seller" => [
                "id" => $this->seller_party_id,
                "name" => $this->relationLoaded("seller") ? $this->seller?->legal_name : null,
            ],
            "buyer" => [
                "id" => $this->buyer_party_id,
                "name" => $this->relationLoaded("buyer") ? $this->buyer?->legal_name : null,
            ],
            "type" => $this->type->toTrans(),
            "status" => $this->status->toTrans(),
            "settlement_status" => $this->settlementStatus(),
            "purpose" => $this->purpose->toTrans(),
            "invoice_kind" => $this->invoice_kind->toTrans(),
            "total" => $this->total->withConvertedDefaultCurrency($this->currency_rate),
            "issued_at" => dateTimeFormat($this->issued_at),
            "download_url" => $this->getDownloadUrl(),
            "pdf_url" => $this->getPDFUrl(),
        ];
    }

    private function settlementStatus(): array
    {
        $total = (float) $this->getRawOriginal('total');
        $netPaid = (float) $this->getRawOriginal('net_paid');
        $refunded = (float) $this->getRawOriginal('refunded_amount');

        if ($refunded > 0) {
            return $this->statusPayload('refunded', 'warning', 'tabler-arrow-back-up');
        }

        if ($netPaid >= $total && $total > 0) {
            return $this->statusPayload('paid', 'success', 'tabler-circle-check');
        }

        if ($netPaid > 0) {
            return $this->statusPayload('partial', 'info', 'tabler-adjustments-dollar');
        }

        return $this->statusPayload('unpaid', 'error', 'tabler-clock-dollar');
    }

    private function statusPayload(string $id, string $color, string $icon): array
    {
        return [
            'id' => $id,
            'name' => __("invoice::invoices.settlement_statuses.{$id}"),
            'color' => $color,
            'icon' => $icon,
        ];
    }
}
