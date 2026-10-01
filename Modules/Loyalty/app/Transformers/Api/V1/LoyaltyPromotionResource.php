<?php

namespace Modules\Loyalty\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Models\LoyaltyPromotion;

/** @mixin LoyaltyPromotion */
class LoyaltyPromotionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "loyalty_program" => [
                "id" => $this->loyalty_program_id,
                "name" => $this->relationLoaded("loyaltyProgram") ? $this->loyaltyProgram->name : "",
            ],
            'value' => $this->value_text,
            "type" => $this->type->toTrans(),
            "total_used" => $this->total_used,
            "total_customers" => $this->total_customers,
            "is_active" => $this->is_active,
            "created_at" => dateTimeFormat($this->created_at),
            "updated_at" => dateTimeFormat($this->updated_at),
        ];
    }
}
