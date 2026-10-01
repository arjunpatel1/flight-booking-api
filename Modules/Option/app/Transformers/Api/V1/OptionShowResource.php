<?php

namespace Modules\Option\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Option\Models\Option;

/** @mixin Option */
class OptionShowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new OptionResource($this))->resolve(),
            "values" => $this->relationLoaded("values") ? OptionValueResource::collection($this->values) : [],
        ];
    }
}
