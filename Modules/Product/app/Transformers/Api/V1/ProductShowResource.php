<?php

namespace Modules\Product\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Option\Transformers\Api\V1\OptionShowResource;
use Modules\Product\Models\Product;
use Modules\Support\Enums\DateTimeFormat;

/** @mixin Product */
class ProductShowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new ProductResource($this))->resolve(),
            "menu_id" => $this->menu_id,
            "description" => $this->description,
            "categories" => $this->relationLoaded("categories") ? $this->categories->pluck('id')->toArray() : [],
            "taxes" => $this->relationLoaded("taxes") ? $this->taxes->pluck('id')->toArray() : [],
            "options" => $this->relationLoaded("options") ? OptionShowResource::collection($this->options) : [],
            "ingredients" => $this->relationLoaded("ingredients") ? IngredientableResource::collection($this->ingredients) : [],
            "special_price" => $this->special_price,
            "special_price_type" => $this->special_price_type,
            "special_price_start" => dateTimeFormat($this->special_price_start, DateTimeFormat::Date),
            "special_price_end" => dateTimeFormat($this->special_price_end, DateTimeFormat::Date),
            "new_from" => dateTimeFormat($this->new_from, DateTimeFormat::Date),
            "new_to" => dateTimeFormat($this->new_to, DateTimeFormat::Date),
            "is_available" => $this->is_available,
            "note" => $this->notes,
            "product_prices" => $this->relationLoaded("productPrices")
                ? $this->productPrices->map(fn($pp) => [
                    'price_type_id' => $pp->price_type_id,
                    'is_global'     => (bool) $pp->is_global,
                    'price'         => $pp->price,
                ])->values()
                : [],
        ];
    }
}
