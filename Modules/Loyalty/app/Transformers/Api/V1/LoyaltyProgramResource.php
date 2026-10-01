<?php

namespace Modules\Loyalty\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Models\LoyaltyProgram;

/** @mixin LoyaltyProgram */
class LoyaltyProgramResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "earning_rate" => $this->earning_rate,
            "redemption_rate" => $this->redemption_rate,
            "min_redeem_points" => $this->min_redeem_points,
            "points_expire_after" => $this->points_expire_after,
            "is_active" => $this->is_active,
            "created_at" => dateTimeFormat($this->created_at),
            "updated_at" => dateTimeFormat($this->updated_at),
        ];
    }
}
