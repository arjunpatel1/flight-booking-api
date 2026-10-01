<?php

namespace Modules\Loyalty\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Models\LoyaltyGift;

/** @mixin LoyaltyGift */
class LoyaltyGiftResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "customer" => $this->relationLoaded("customer") && $this->customer->relationLoaded("customer")
                ? [
                    "id" => $this->customer->customer->id,
                    "name" => $this->customer->customer->name,
                ]
                : null,
            "program" => [
                "id" => $this->loyalty_program_id,
                "name" => $this->relationLoaded("program") ? $this->program->name : "",
            ],
            "reward" => [
                "id" => $this->loyalty_reward_id,
                "name" => $this->relationLoaded("reward") ? $this->reward->name : "",
            ],
            "status" => $this->status->toTrans(),
            "type" => $this->type->toTrans(),
            "points_spent" => "$this->points_spent Pts",
            "valid_from" => dateTimeFormat($this->valid_from),
            "valid_until" => dateTimeFormat($this->valid_until),
            "used_at" => dateTimeFormat($this->used_at),
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
