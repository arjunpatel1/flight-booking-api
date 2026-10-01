<?php

namespace Modules\Pos\Transformers\Api\V1\Pos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Option\Models\Option;

/** @mixin Option */
class PosProductOptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "reference" => $this->uuid,
            "name" => $this->name,
            "type" => $this->type->toTrans(),
            "type_id" => $this->type->value,
            "is_required" => $this->is_required,
            "values" => $this->relationLoaded("values")
                ? PosProductOptionValueResource::collection($this->values)
                : null,
        ];
    }
}
