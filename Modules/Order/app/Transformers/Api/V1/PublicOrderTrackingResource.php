<?php

namespace Modules\Order\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Models\Order;
use Modules\Order\Support\KitchenSla;
use Modules\Invoice\Enums\InvoiceKind;
use Modules\Order\Delivery\DeliveryReadModel;

/** @mixin Order */
class PublicOrderTrackingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $invoice = $this->relationLoaded('invoices')
            ? $this->invoices->first(fn ($candidate) => $candidate->invoice_kind === InvoiceKind::Standard)
            : null;

        return [
            'id' => $this->reference_no,
            'reference_no' => $this->reference_no,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'status_text' => $this->status->trans(),
            'payment_status' => $this->payment_status?->value,
            'payment_status_text' => $this->payment_status?->toTrans(),
            'can_edit' => $this->payment_status?->isUnpaid()
                && in_array($this->status, [OrderStatus::Pending, OrderStatus::Confirmed], true)
                && $this->products->every(fn ($product) => in_array($product->status, [OrderProductStatus::Pending, OrderProductStatus::Cancelled], true)),
            'version' => $this->updated_at?->toISOString(),
            'total' => $this->total->withConvertedDefaultCurrency($this->currency_rate),
            'subtotal' => $this->subtotal->withConvertedDefaultCurrency($this->currency_rate),
            'customer_delivery_fee' => (float) data_get($this->fulfilmentDetails(), 'customer_delivery_fee', 0),
            'discount' => $this->relationLoaded('discount') && $this->discount ? [
                'name' => $this->discount->name,
                'amount' => $this->discount->amount->withConvertedDefaultCurrency($this->currency_rate),
            ] : null,
            'order_type' => $this->type?->value,
            'delivery' => $this->type?->value === 'delivery'
                ? DeliveryReadModel::customer($this->relationLoaded('delivery') ? $this->delivery : null)
                : null,
            'table' => [
                'name' => $this->relationLoaded('table') ? $this->table?->name : null,
            ],
            'branch' => [
                'reference' => $this->relationLoaded('branch') ? $this->branch?->uuid : null,
                'name' => $this->relationLoaded('branch') ? $this->branch?->name : null,
                'menu_slug' => $this->relationLoaded('branch')
                    ? $this->branch?->onlineMenus?->firstWhere('is_active', true)?->slug
                    : null,
            ],
            'items' => $this->products->map(fn ($item) => [
                'product_reference' => $item->product?->uuid,
                'product_name' => $item->name,
                'image_url' => $item->product?->thumbnail_url,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price->withConvertedDefaultCurrency($item->currency_rate),
                'total' => $item->total->withConvertedDefaultCurrency($item->currency_rate),
                'status' => $item->status->value,
                'status_text' => $item->status->trans(),
            ])->values(),
            'created_at' => $this->created_at?->toIso8601String(),
            'estimated_completion' => $this->estimatedCompletion()?->toIso8601String(),
            'room_number' => $this->extractRoomNumber(),
            'customer_name' => $this->relationLoaded('customer') ? $this->customer?->name : null,
            'notes' => $this->notes,
            'invoice' => $invoice ? [
                'invoice_number' => $invoice->invoice_number,
                'pdf_url' => $invoice->getPDFUrl(),
                'download_url' => $invoice->getDownloadUrl(),
            ] : null,
        ];
    }

    private function estimatedCompletion()
    {
        if (in_array($this->status, [OrderStatus::Completed, OrderStatus::Served, OrderStatus::Cancelled, OrderStatus::Refunded], true)) {
            return null;
        }

        return app(KitchenSla::class)->estimatedCompletion($this->resource);
    }

    private function extractRoomNumber(): ?string
    {
        if ($this->table_id) {
            return $this->relationLoaded('table') && filled($this->table?->name)
                ? $this->table->name
                : __('pos::qr_order.table_with_number', ['number' => $this->table_id]);
        }

        if (filled($this->resource->fulfilmentDetails()['room_number'] ?? null)) {
            return $this->resource->fulfilmentDetails()['room_number'];
        }

        if (preg_match('/Room \/ Table:\s*(.+)$/im', (string) $this->notes, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/Room Number:\s*(.+)$/im', (string) $this->notes, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/Room\s+(.+?)\s+-\s+QR Order/i', (string) $this->notes, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
