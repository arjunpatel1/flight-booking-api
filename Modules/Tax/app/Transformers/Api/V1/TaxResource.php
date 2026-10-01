<?php

namespace Modules\Tax\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Tax\Models\Tax;

/** @mixin Tax */
class TaxResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            "branch" => [
                "id" => $this->branch_id,
                "name" => $this->relationLoaded("branch") ? $this->branch?->name : "",
            ],
            "rate" => $this->rate,
            'code' => $this->code,
            'type' => $this->type->toTrans(),
            'compound' => $this->compound,
            "is_active" => $this->is_active,
            "is_global" => $this->is_global,
            "order_types" => $this->order_types ?: [],
            "gst_type"    => $this->gst_type?->value,
            "updated_at" => dateTimeFormat($this->updated_at),
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
