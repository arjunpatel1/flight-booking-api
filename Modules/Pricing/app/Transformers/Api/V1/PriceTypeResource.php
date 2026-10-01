<?php

namespace Modules\Pricing\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pricing\Models\PriceType;

/** @mixin PriceType */
class PriceTypeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'rule_type' => $this->rule_type->toTrans(),
            'rule_value' => $this->rule_value,
            'description' => $this->description,
            'created_by' => [
                'id' => $this->created_by,
                'name' => $this->relationLoaded('createdBy') ? $this->createdBy?->name : null,
            ],
            'is_active' => $this->is_active,
            'updated_at' => dateTimeFormat($this->updated_at),
            'created_at' => dateTimeFormat($this->created_at),
        ];
    }
}
