<?php

namespace Modules\Loyalty\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Models\LoyaltyReward;
use Modules\Media\Transformers\Api\V1\MediaSimpleResource;

/** @mixin LoyaltyReward */
class LoyaltyRewardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "icon" => $this->icon != null ? new MediaSimpleResource($this->icon) : null,
            "loyalty_program" => [
                "id" => $this->loyalty_program_id,
                "name" => $this->relationLoaded("loyaltyProgram") ? $this->loyaltyProgram->name : "",
            ],
            "type" => $this->type->toTrans(),
            "points_cost" => $this->points_cost,
            "total_redeemed" => $this->total_redeemed,
            "total_customers" => $this->total_customers,
            "is_active" => $this->is_active,
            "created_at" => dateTimeFormat($this->created_at),
            "updated_at" => dateTimeFormat($this->updated_at),
        ];
    }
}
