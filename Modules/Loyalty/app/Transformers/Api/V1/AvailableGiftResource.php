<?php

namespace Modules\Loyalty\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Models\LoyaltyGift;
use Modules\Media\Transformers\Api\V1\MediaSimpleResource;

/** @mixin LoyaltyGift */
class AvailableGiftResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "type" => $this->type->toTrans(),
            "reward" => [
                "id" => $this->loyalty_reward_id,
                "name" => $this->relationLoaded("reward") ? $this->reward->name : "",
                "icon" => $this->relationLoaded("reward")
                    ? ($this->reward->icon != null ? new MediaSimpleResource($this->reward->icon) : null)
                    : null,
            ],
        ];
    }
}
