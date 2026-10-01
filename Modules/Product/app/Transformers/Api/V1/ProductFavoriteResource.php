<?php

namespace Modules\Product\Transformers\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Product\Models\ProductFavorite;

class ProductFavoriteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request
     * @return array
     */
    public function toArray($request): array
    {
        /** @var ProductFavorite $this */
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            'product_id' => $this->product_id,
            'product_name' => $this->product?->name,
            'product_price' => $this->product?->price,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
