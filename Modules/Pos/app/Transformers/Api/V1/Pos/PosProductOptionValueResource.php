<?php

namespace Modules\Pos\Transformers\Api\V1\Pos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Option\Models\OptionValue;

/** @mixin OptionValue */
class PosProductOptionValueResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->uuid,
            "label" => $this->label,
            "price_type" => $this->price_type,
            "price" => $this->price,
        ];
    }
}
