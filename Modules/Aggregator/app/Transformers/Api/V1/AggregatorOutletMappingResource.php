<?php

namespace Modules\Aggregator\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Aggregator\Models\AggregatorOutletMapping;

/** @mixin AggregatorOutletMapping */
class AggregatorOutletMappingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'integration' => [
                'id' => $this->aggregator_integration_id,
                'name' => $this->relationLoaded('integration') ? $this->integration?->name : '',
            ],
            'branch' => [
                'id' => $this->branch_id,
                'name' => $this->relationLoaded('branch') ? $this->branch?->name : '',
            ],
            'external_outlet_id' => $this->external_outlet_id,
            'external_outlet_name' => $this->external_outlet_name,
            'meta' => $this->meta,
            'is_active' => $this->is_active,
            'updated_at' => dateTimeFormat($this->updated_at),
            'created_at' => dateTimeFormat($this->created_at),
        ];
    }
}
