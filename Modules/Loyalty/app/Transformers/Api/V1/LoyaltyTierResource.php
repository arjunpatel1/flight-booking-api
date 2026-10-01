<?php

namespace Modules\Loyalty\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Models\LoyaltyTier;
use Modules\Media\Transformers\Api\V1\MediaSimpleResource;

/** @mixin LoyaltyTier */
class LoyaltyTierResource extends JsonResource
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
            "benefits" => $this->benefits,
            "loyalty_program" => [
                "id" => $this->loyalty_program_id,
                "name" => $this->relationLoaded("loyaltyProgram") ? $this->loyaltyProgram->name : "",
            ],
            "min_spend" => $this->min_spend,
            "multiplier" => $this->multiplier,
            "is_active" => $this->is_active,
            "order" => $this->order,
            "created_at" => dateTimeFormat($this->created_at),
            "updated_at" => dateTimeFormat($this->updated_at),
        ];
    }
}
