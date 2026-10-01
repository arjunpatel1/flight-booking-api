<?php

namespace Modules\WhatsAppCenter\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppOrderSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'branch' => $this->branch ? ['uuid' => $this->branch->uuid, 'name' => $this->branch->name] : null,
            'order_type' => $this->order_type,
            'state' => $this->state,
            'delivery_address' => $this->delivery_address,
            'quoted_total' => $this->quoted_total,
            'has_order' => $this->order !== null,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'order' => $this->order ? [
                'reference_no' => $this->order->reference_no,
                'status' => $this->order->status?->value ?? $this->order->status,
                'payment_status' => $this->order->payment_status?->value ?? $this->order->payment_status,
                'total' => $this->order->total?->amount(),
            ] : null,
        ];
    }
}
