<?php

namespace Modules\Loyalty\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Models\LoyaltyReward;
use Modules\Support\Enums\DateTimeFormat;

/** @mixin LoyaltyReward */
class ShowLoyaltyRewardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new LoyaltyRewardResource($this))->resolve(),
            "description" => $this->description,
            "loyalty_tier" => [
                "id" => $this->loyalty_tier_id,
            ],
            "currency" => $this->currency,
            "value" => $this->value,
            "value_type" => $this->value_type->toTrans(),
            "max_redemptions_per_order" => $this->max_redemptions_per_order,
            "usage_limit" => $this->usage_limit,
            "per_customer_limit" => $this->per_customer_limit,
            "conditions" => $this->conditions,
            "meta" => $this->meta,
            "starts_at" => dateTimeFormat($this->starts_at, DateTimeFormat::Date),
            "ends_at" => dateTimeFormat($this->ends_at, DateTimeFormat::Date),
        ];
    }
}
