<?php

namespace Modules\Category\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Category\Models\Category;
use Modules\Media\Transformers\Api\V1\MediaSimpleResource;

/** @mixin Category */
class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "logo" => $this->logo != null ? new MediaSimpleResource($this->logo) : null,
            "is_active" => $this->is_active,
            "parent_id" => $this->parent_id,
            "slug" => $this->slug,
            "updated_at" => dateTimeFormat($this->updated_at),
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
