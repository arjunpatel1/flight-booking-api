<?php

namespace Modules\Pos\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pos\Models\PosCashMovement;

/** @mixin PosCashMovement */
class PosCashMovementShowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new PosCashMovementResource($this))->resolve(),
            "created_by" => [
                "id" => $this->created_by,
                "name" => $this->relationLoaded("createdBy") ? $this->createdBy?->name : "",
            ],
            "currency" => $this->currency,
            "currency_rate" => $this->currency_rate,
            "reference" => $this->reference,
            "notes" => $this->notes,
        ];
    }
}
