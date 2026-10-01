<?php

namespace Modules\Hotels\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotels\Models\Hotel;

/** @mixin Hotel */
class HotelResource extends JsonResource
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
                "name" => $this->relationLoaded("branch") ? $this->branch->name : "",
            ],
            "is_active" => $this->is_active,
            "updated_at" => dateTimeFormat($this->updated_at),
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
