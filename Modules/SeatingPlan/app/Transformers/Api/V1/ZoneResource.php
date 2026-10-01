<?php

namespace Modules\SeatingPlan\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SeatingPlan\Models\Zone;

/** @mixin Zone */
class ZoneResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "branch" => [
                "id" => $this->branch_id,
                "name" => $this->relationLoaded("branch") ? $this->branch?->name : "",
            ],
            "floor" => [
                "id" => $this->floor_id,
                "name" => $this->relationLoaded("floor") ? $this->floor?->name : "",
            ],
            "payment_type" => $this->price_type_id ? [
                "id" => $this->price_type_id,
                "name" => $this->relationLoaded("priceType") ? $this->priceType?->name : "",
            ] : null,
            "is_active" => $this->is_active,
            "updated_at" => dateTimeFormat($this->updated_at),
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
