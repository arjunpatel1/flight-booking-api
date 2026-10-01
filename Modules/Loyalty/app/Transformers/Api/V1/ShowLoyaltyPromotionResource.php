<?php

namespace Modules\Loyalty\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Models\LoyaltyPromotion;
use Modules\Support\Enums\DateTimeFormat;

/** @mixin LoyaltyPromotion */
class ShowLoyaltyPromotionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new LoyaltyPromotionResource($this))->resolve(),
            "bonus_points" => $this->bonus_points,
            "multiplier" => $this->multiplier,
            "description" => $this->description,
            "usage_limit" => $this->usage_limit,
            "per_customer_limit" => $this->per_customer_limit,
            "starts_at" => dateTimeFormat($this->starts_at, DateTimeFormat::Date),
            "ends_at" => dateTimeFormat($this->ends_at, DateTimeFormat::Date),
            "conditions" => $this->conditions,
        ];
    }
}
