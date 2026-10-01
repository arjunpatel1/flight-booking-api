<?php

namespace Modules\Product\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Media\Transformers\Api\V1\MediaSimpleResource;
use Modules\Product\Models\Product;

/** @mixin Product */
class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $attributes = $this->getAttributes();

        return [
            "id" => $this->id,
            "sku" => $this->sku,
            "name" => $this->name,
            "price" => $this->price,
            "selling_price" => $this->selling_price,
            "has_special_price" => $this->hasSpecialPrice(),
            "thumbnail" => $this->thumbnail != null ? new MediaSimpleResource($this->thumbnail) : null,
            "is_active" => $this->is_active,
            'is_available' => $this->is_available,
            'is_recommended' => $this->is_recommended,
            'is_best_seller' => $this->is_best_seller,
            'display_priority' => $this->display_priority,
            'note' => $this->notes,
            'allergens' => $this->allergens ?? [],
            'dietary_labels' => $this->dietary_labels ?? [],
            'food_type' => $attributes['food_type'] ?? null,
            "updated_at" => dateTimeFormat($this->updated_at),
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
