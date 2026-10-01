<?php

namespace Modules\Pos\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pos\Models\PosSession;

/** @mixin PosSession */
class PosSessionShowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new PosSessionResource($this))->resolve(),
            "opening_float" => $this->opening_float,
            "declared_cash" => $this->declared_cash,
            "system_cash_sales" => $this->system_cash_sales,
            "cash_over_short" => $this->cash_over_short,
            "system_card_sales" => $this->system_card_sales,
            "system_other_sales" => $this->system_other_sales,
            "total_sales" => $this->total_sales,
            "total_refunds" => $this->total_refunds,
            "orders_count" => $this->orders_count,
            "notes" => $this->notes
        ];
    }
}
