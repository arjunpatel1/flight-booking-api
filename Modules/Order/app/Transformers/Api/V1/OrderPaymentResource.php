<?php

namespace Modules\Order\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payment\Models\Payment;

/** @mixin Payment */
class OrderPaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "transaction_id" => $this->transaction_id,
            "method" => $this->method->toTrans(),
            "type" => $this->type->toTrans(),
            "status" => $this->status?->toTrans(),
            "amount" => $this->amount->withConvertedDefaultCurrency($this->currency_rate),
            "gateway" => $this->gateway,
            "gateway_transaction_id" => $this->gateway_transaction_id,
            "processed_at" => $this->processed_at?->toISOString(),
            "has_payment_slip" => filled(data_get($this->meta, 'payment_slip_path')),
            "payment_slip_url" => filled(data_get($this->meta, 'payment_slip_path'))
                ? url('/v1/orders/'.$this->order_id.'/payments/'.$this->id.'/slip') : null,
            "created_at" => dateTimeFormat($this->created_at)
        ];
    }
}
