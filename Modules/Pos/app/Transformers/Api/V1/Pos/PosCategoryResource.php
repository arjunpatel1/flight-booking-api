<?php

namespace Modules\Pos\Transformers\Api\V1\Pos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Category\Models\Category;

/** @mixin Category */
class PosCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "logo" => $this->logo?->preview_image_url,
            'items' => PosCategoryResource::collection($this->whenLoaded('childrenRecursive')),
        ];
    }
}
