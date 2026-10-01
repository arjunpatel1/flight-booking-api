<?php

namespace Modules\Loyalty\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Models\LoyaltyCustomer;

/** @mixin LoyaltyCustomer */
class LoyaltyCustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "customer" => [
                "id" => $this->customer_id,
                "name" => $this->relationLoaded("customer") ? $this->customer?->name : "",
            ],
            "loyalty_program" => [
                "id" => $this->loyalty_program_id,
                "name" => $this->relationLoaded("loyaltyProgram") ? $this->loyaltyProgram?->name : "",
            ],
            "loyalty_tier" => [
                "id" => $this->loyalty_tier_id,
                "name" => $this->relationLoaded("loyaltyTier") ? $this->loyaltyTier?->name : "",
            ],
            "points_balance" => $this->points_balance,
            "lifetime_points" => $this->lifetime_points,
            "last_earned_at" => dateTimeFormat($this->last_earned_at),
            "last_redeemed_at" => dateTimeFormat($this->last_redeemed_at),
            "created_at" => dateTimeFormat($this->created_at),
            "updated_at" => dateTimeFormat($this->updated_at),
        ];
    }
}
