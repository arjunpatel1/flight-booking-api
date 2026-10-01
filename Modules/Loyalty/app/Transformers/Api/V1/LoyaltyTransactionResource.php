<?php

namespace Modules\Loyalty\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Models\LoyaltyTransaction;

/** @mixin LoyaltyTransaction */
class LoyaltyTransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "customer" => [
                "id" => $this->loyalty_customer_id,
                "name" => $this->relationLoaded("customer") && $this->customer?->relationLoaded("customer") ? $this->customer?->customer?->name : "",
            ],
            "description" => $this->description,
            "type" => $this->type->toTrans(),
            "points" => "$this->points Pts",
            "amount" => $this->amount,
            "created_at" => dateTimeFormat($this->created_at),
            "updated_at" => dateTimeFormat($this->updated_at),
        ];
    }
}
