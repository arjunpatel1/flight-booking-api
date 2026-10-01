<?php

namespace Modules\Aggregator\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Aggregator\Models\AggregatorMenuMapping;

/** @mixin AggregatorMenuMapping */
class AggregatorMenuMappingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'integration' => [
                'id' => $this->aggregator_integration_id,
                'name' => $this->relationLoaded('integration') ? $this->integration?->name : '',
            ],
            'menu' => [
                'id' => $this->menu_id,
                'name' => $this->relationLoaded('menu') ? $this->menu?->name : '',
            ],
            'external_menu_id' => $this->external_menu_id,
            'meta' => $this->meta,
            'sync_enabled' => $this->sync_enabled,
            'last_synced_at' => dateTimeFormat($this->last_synced_at),
            'updated_at' => dateTimeFormat($this->updated_at),
            'created_at' => dateTimeFormat($this->created_at),
        ];
    }
}
