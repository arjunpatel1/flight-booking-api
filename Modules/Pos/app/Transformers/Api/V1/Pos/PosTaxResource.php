<?php

namespace Modules\Pos\Transformers\Api\V1\Pos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Tax\Models\Tax;

/** @mixin Tax */
class PosTaxResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "code" => $this->code,
            "name" => $this->name,
            "rate" => $this->rate,
            "type" => $this->type,
            "compound" => $this->compound,
            'order_types' => $this->order_types,
        ];
    }
}
